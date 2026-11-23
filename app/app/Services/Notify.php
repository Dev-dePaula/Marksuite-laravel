<?php

namespace App\Services;

use App\Models\{Invoice, Notification, Setting};
use App\Support\Tenancy;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

/**
 * Notificações de facturação por WhatsApp e email (porta de notify.js).
 * Sem credenciais, a mensagem fica registada como SIMULADA (nada sai da máquina).
 */
class Notify
{
    public const EVENTS = ['EMITIDA', 'A_VENCER', 'VENCIDA', 'PARCIAL', 'PAGA'];
    private const DEFAULT_ON = ['EMITIDA', 'A_VENCER', 'VENCIDA', 'PAGA'];

    private static function e(string $k): string { return trim((string) env($k)); }

    public static function config(): array
    {
        $s = Setting::whereIn('key', ['notifyEvents', 'notifyDueDays', 'notifyWhatsapp', 'notifyEmail'])->pluck('value', 'key');
        return [
            'events' => isset($s['notifyEvents']) ? array_values(array_filter(explode(',', $s['notifyEvents']), fn ($x) => in_array($x, self::EVENTS))) : self::DEFAULT_ON,
            'dueDays' => min(30, max(1, (int) ($s['notifyDueDays'] ?? 3) ?: 3)),
            'whatsapp' => ($s['notifyWhatsapp'] ?? '1') !== '0', 'email' => ($s['notifyEmail'] ?? '1') !== '0',
        ];
    }

    public static function saveConfig(array $d): array
    {
        if (isset($d['events'])) Rules::setSetting('notifyEvents', implode(',', array_filter($d['events'], fn ($x) => in_array($x, self::EVENTS))));
        if (isset($d['dueDays'])) Rules::setSetting('notifyDueDays', $d['dueDays']);
        if (isset($d['whatsapp'])) Rules::setSetting('notifyWhatsapp', $d['whatsapp'] ? '1' : '0');
        if (isset($d['email'])) Rules::setSetting('notifyEmail', $d['email'] ? '1' : '0');
        return self::config();
    }

    public static function providers(): array
    {
        return ['whatsapp' => (bool) (self::e('WHATSAPP_TOKEN') && self::e('WHATSAPP_PHONE_ID')),
            'email' => (bool) (self::e('MAIL_HOST') && self::e('MAIL_USERNAME') && self::e('MAIL_PASSWORD')), 'publicUrl' => self::e('PUBLIC_API_URL') ?: null];
    }

    // ---- ligação pública assinada (inclui o tenant: a página pública não tem sessão) ----
    private static function b64(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }

    public static function docToken(int $id): string
    {
        $p = self::b64(json_encode(['i' => $id, 't' => Tenancy::id(), 'x' => now()->addDays(180)->timestamp]));
        return $p . '.' . self::b64(hash_hmac('sha256', $p, config('app.key') . ':doc-link', true));
    }

    /** @return array{0:int,1:int}|null [tenantId, invoiceId] */
    public static function verifyDocToken(string $t): ?array
    {
        [$p, $sig] = array_pad(explode('.', $t, 2), 2, '');
        if (!$p || !hash_equals(self::b64(hash_hmac('sha256', $p, config('app.key') . ':doc-link', true)), $sig)) return null;
        $d = json_decode(base64_decode(strtr($p, '-_', '+/')), true);
        return is_array($d) && ($d['x'] ?? 0) > time() ? [(int) $d['t'], (int) $d['i']] : null;
    }

    public static function docLink(int $id): string
    {
        return rtrim(self::e('PUBLIC_API_URL') ?: config('app.url'), '/') . '/api/public/doc/' . self::docToken($id);
    }

    // ---- mensagens ----
    public static function kz($n): string { return number_format((float) $n, 2, ',', ' ') . ' Kz'; }
    private static function br($d): string { return Carbon::parse($d)->format('d/m/Y'); }

    public static function normPhone($raw): ?string
    {
        $d = preg_replace('/\D/', '', (string) $raw);
        if (str_starts_with($d, '00')) $d = substr($d, 2);
        if (strlen($d) === 9 && $d[0] === '9') $d = "244$d";
        return preg_match('/^\d{11,15}$/', $d) ? $d : null;
    }

    public static function buildMessage(string $event, Invoice $inv, string $link, ?array $late = null): array
    {
        $who = $inv->counterparty; $co = $inv->company->name; $open = $inv->amount - $inv->paidAmount; $doc = $inv->docNumber; $k = fn ($n) => self::kz($n);
        $t = [
            'EMITIDA' => ["$co: $doc emitida", "Olá $who, a $co emitiu o documento $doc no valor de {$k($inv->amount)}, com vencimento a " . self::br($inv->dueDate) . ". Pode ver e imprimir aqui: $link"],
            'A_VENCER' => ["$co: $doc vence em breve", "Olá $who, lembramos que o documento $doc ({$k($open)} em aberto) vence a " . self::br($inv->dueDate) . ". Ver documento: $link"],
            'VENCIDA' => ["$co: $doc em atraso", "Olá $who, o documento $doc ({$k($open)} em aberto) venceu a " . self::br($inv->dueDate) . '. Pedimos que regularize o pagamento.'
                . ($late && $late['total'] > 0 ? " Encargos por atraso até hoje (multa e juros de mora): {$k($late['total'])}." : '') . " Ver documento: $link. Se já pagou, desconsidere esta mensagem."],
            'PARCIAL' => ["$co: pagamento parcial de $doc", "Olá $who, recebemos um pagamento parcial do documento $doc. Valor ainda em aberto: {$k($open)}. Obrigado. $link"],
            'PAGA' => ["$co: $doc paga — obrigado", "Olá $who, confirmamos o pagamento do documento $doc ({$k($inv->amount)}). Obrigado pela preferência! Comprovativo: $link"],
        ][$event];
        $val = in_array($event, ['A_VENCER', 'VENCIDA', 'PARCIAL']) ? $open : $inv->amount;
        return ['subject' => $t[0], 'text' => $t[1], 'params' => [$who, $doc, $k($val), self::br($inv->dueDate), $link]];
    }

    // ---- envio ----
    private static function sendWhatsApp(string $to, array $msg, string $event): ?string
    {
        $url = 'https://graph.facebook.com/' . (self::e('WHATSAPP_API_VERSION') ?: 'v21.0') . '/' . self::e('WHATSAPP_PHONE_ID') . '/messages';
        $tpl = self::e("WHATSAPP_TPL_$event");
        $body = $tpl
            ? ['messaging_product' => 'whatsapp', 'to' => $to, 'type' => 'template', 'template' => ['name' => $tpl, 'language' => ['code' => self::e('WHATSAPP_TPL_LANG') ?: 'pt_PT'],
                'components' => [['type' => 'body', 'parameters' => array_map(fn ($p) => ['type' => 'text', 'text' => (string) $p], $msg['params'])]]]]
            : ['messaging_product' => 'whatsapp', 'to' => $to, 'type' => 'text', 'text' => ['body' => $msg['text'], 'preview_url' => true]];
        $r = Http::timeout(15)->withToken(self::e('WHATSAPP_TOKEN'))->post($url, $body);
        if (!$r->ok()) throw new \RuntimeException($r->json('error.message') ?: 'WhatsApp HTTP ' . $r->status());
        return $r->json('messages.0.id');
    }

    private static function sendEmail(string $to, array $msg): ?string
    {
        Mail::raw($msg['text'], fn ($m) => $m->to($to)->subject($msg['subject']));
        return null;
    }

    /** Envia (ou regista) a notificação por todos os canais activos. Nunca lança erro. */
    public static function invoice(int $invoiceId, string $event, bool $manual = false, ?array $channels = null): array
    {
        $out = [];
        try {
            $cfg = self::config();
            if (!$manual && !in_array($event, $cfg['events'])) return $out;
            $inv = Invoice::with(['company', 'contact'])->find($invoiceId);
            if (!$inv || $inv->kind !== 'RECEBER' || $inv->draft || $inv->cancelledAt || !$inv->contact) return $out;
            $c = $inv->contact; $link = self::docLink($inv->id); $pv = self::providers();
            $late = $event === 'VENCIDA' ? Billing::lateOf($inv->amount - $inv->paidAmount, $inv->dueDate, Billing::lateConfig()) : null;
            $msg = self::buildMessage($event, $inv, $link, $late);
            $targets = []; $want = fn ($ch) => !$channels || in_array($ch, $channels);
            if ($want('whatsapp') && $cfg['whatsapp'] && $c->notifyWhatsapp && ($n = self::normPhone($c->whatsapp ?: $c->phone))) $targets[] = ['whatsapp', $n];
            if ($want('email') && $cfg['email'] && $c->notifyEmail && $c->email) $targets[] = ['email', $c->email];
            foreach ($targets as [$ch, $to]) {
                $row = Notification::create(['invoiceId' => $inv->id, 'channel' => $ch, 'event' => $event, 'toAddress' => $to, 'body' => $msg['text'], 'manual' => $manual]);
                $status = 'ENVIADA'; $err = null; $pid = null;
                try {
                    if (!$pv[$ch]) $status = 'SIMULADA';
                    else $pid = $ch === 'whatsapp' ? self::sendWhatsApp($to, $msg, $event) : self::sendEmail($to, $msg);
                } catch (\Throwable $e) { $status = 'FALHOU'; $err = mb_substr($e->getMessage(), 0, 480); }
                $row->update(['status' => $status, 'error' => $err, 'providerId' => $pid, 'sentAt' => $status === 'FALHOU' ? null : now()]);
                $out[] = $row->fresh();
            }
        } catch (\Throwable $e) { report($e); }
        return $out;
    }

    /** Depois da resposta (não bloqueia a facturação); preserva o tenant. */
    public static function later(int $invoiceId, string $event): void
    {
        $tid = Tenancy::id();
        dispatch(function () use ($tid, $invoiceId, $event) {
            Tenancy::set($tid);
            try { self::invoice($invoiceId, $event); } finally { Tenancy::set(null); }
        })->afterResponse();
    }

    /** Lembretes de vencimento do tenant activo. */
    public static function runDue(bool $force = false): array
    {
        $cfg = self::config();
        $h = (now('UTC')->hour + 1) % 24; // Africa/Luanda = UTC+1
        if (!$force && ($h < 8 || $h >= 19)) return ['skipped' => 'fora do horário (08h–19h, hora de Luanda)'];
        $today = Rules::today(); $sent = 0;
        $open = Invoice::with('notifications')->where('kind', 'RECEBER')->where('draft', false)->whereNull('cancelledAt')->whereNull('paidAt')
            ->whereNotNull('contactId')->whereIn('docType', ['FT', 'FA', 'ND', 'AF', 'FG'])->get();
        foreach ($open as $i) {
            $left = Rules::daysBetween($i->dueDate, $today);
            $ev = fn ($n) => $i->notifications->where('event', $n);
            $event = null;
            if ($left < 0 && in_array('VENCIDA', $cfg['events'])) {
                $last = $ev('VENCIDA')->max('createdAt');
                $count = $ev('VENCIDA')->map(fn ($n) => $n->createdAt->toDateString())->unique()->count();
                if ($count < 4 && (!$last || $last->diffInDays(now()) >= 7)) $event = 'VENCIDA';
            } elseif ($left >= 0 && $left <= $cfg['dueDays'] && in_array('A_VENCER', $cfg['events']) && !$ev('A_VENCER')->count()) $event = 'A_VENCER';
            if ($event) $sent += count(self::invoice($i->id, $event));
        }
        return ['checked' => $open->count(), 'sent' => $sent];
    }

    public static function test(string $channel, string $to): array
    {
        $pv = self::providers();
        if (!$pv[$channel]) abort(422, $channel === 'whatsapp' ? 'WhatsApp não configurado (WHATSAPP_TOKEN e WHATSAPP_PHONE_ID).' : 'Email não configurado (MAIL_HOST, MAIL_USERNAME e MAIL_PASSWORD).');
        $text = 'Teste do MarkSuite: as notificações de facturação estão ligadas.';
        if ($channel === 'whatsapp') {
            $n = self::normPhone($to) ?? abort(400, 'Número inválido.');
            return ['id' => self::sendWhatsApp($n, ['text' => $text, 'params' => ['Teste', 'FT 0000/0000', '0,00 Kz', '--/--/----', 'https://']], 'TESTE')];
        }
        return ['id' => self::sendEmail($to, ['subject' => 'Teste MarkSuite', 'text' => $text])];
    }
}
