<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\{Company, Contact, Invoice, Item, JournalLine, Notification, Receipt};
use App\Services\{Accounting, Billing, Fiscal, Notify, Render, Rules};
use App\Support\Tenancy;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Documentos de facturação (10 tipos), clientes, catálogo, notificações, conformidade AGT e apuramento de IVA. */
class DocumentController extends Controller
{
    private const MODS = ['facturas' => ['FT', 'FR', 'FA', 'ND', 'AF', 'FG', 'NC'], 'proformas' => ['PF'], 'guias' => ['GT'], 'encomendas' => ['EN']];

    private function companyScope(Request $r): array
    {
        $n = (int) $r->query('companyId');
        return $n > 0 ? ['companyId' => $n] : [];
    }

    private static function stateOf(Invoice $i): string
    {
        if ($i->draft) return 'R';
        if ($i->cancelledAt) return 'C';
        $T = Fiscal::TYPES[$i->docType] ?? null;
        $venc = $i->dueDate < Rules::today();
        if (!$T) return $i->paidAt ? 'P' : ($venc ? 'V' : 'D');
        if ($T['pay']) return $i->paidAt ? 'P' : ($venc ? 'V' : 'D');
        if ($i->docType === 'FR') return 'P';
        return $i->convertedTo ? 'F' : 'E';
    }

    private function rowView(Invoice $i, array $lc): Invoice
    {
        $o = (!$i->draft && !$i->cancelledAt && (Fiscal::TYPES[$i->docType]['pay'] ?? false)) ? max(0, Accounting::r2($i->amount - $i->paidAmount)) : 0;
        $st = self::stateOf($i);
        $i->setAttribute('state', $st); $i->setAttribute('outstanding', $o);
        $i->setAttribute('late', $st === 'V' ? Billing::lateOf($o, $i->dueDate, $lc) : null);
        $i->setAttribute('typeName', Fiscal::TYPES[$i->docType]['n'] ?? $i->docType);
        $i->setAttribute('sg', (Fiscal::TYPES[$i->docType]['neg'] ?? false) ? -1 : 1);
        return $i;
    }

    private function docRules(): array
    {
        return [
            'docType' => ['required', Rule::in(array_keys(Fiscal::TYPES))], 'companyId' => ['required', 'integer', $this->ex('companies')],
            'contactId' => ['nullable', 'integer', $this->ex('contacts')], 'newClient' => 'sometimes|array', 'newClient.name' => 'required_with:newClient|string|max:255',
            'newClient.nif' => 'nullable|string|max:50', 'newClient.email' => 'nullable|string|max:255', 'newClient.phone' => 'nullable|string|max:50',
            'newClient.whatsapp' => 'nullable|string|max:50', 'newClient.address' => 'nullable|string|max:500',
            'issueDate' => 'required|date', 'terms' => 'nullable|string|max:30', 'serie' => 'nullable|string|max:10', 'vref' => 'nullable|string|max:60', 'place' => 'nullable|string|max:120',
            'notes' => 'nullable|string|max:2000', 'gdiscPct' => 'sometimes|numeric|min:0|max:100', 'retPct' => 'sometimes|numeric|min:0|max:100',
            'lines' => 'present|array|max:200', 'lines.*.code' => 'nullable|string|max:40', 'lines.*.description' => 'nullable|string|max:500', 'lines.*.qty' => 'sometimes|numeric|min:0',
            'lines.*.price' => 'sometimes|numeric|min:0', 'lines.*.discPct' => 'sometimes|numeric|min:0|max:100', 'lines.*.ivaRate' => 'sometimes|numeric|min:0|max:100',
            'lines.*.itemId' => ['nullable', 'integer', $this->ex('items')], 'lines.*.newItem' => 'sometimes|boolean',
            'transport' => 'sometimes|array', 'delivery' => 'sometimes|array', 'originId' => ['nullable', 'integer', $this->ex('invoices')], 'emit' => 'sometimes|boolean',
        ];
    }

    private function normalizeDoc(array $d): array
    {
        $d['lines'] = array_map(fn ($l) => $l + ['description' => '', 'qty' => 1, 'price' => 0, 'discPct' => 0, 'ivaRate' => 14], array_map(fn ($l) => array_filter($l, fn ($v) => $v !== null), $d['lines']));
        foreach ($d['lines'] as &$l) $l['description'] = (string) ($l['description'] ?? '');
        return $d;
    }

    public function index(Request $r)
    {
        $q = $r->query(); $types = self::MODS[$q['mod'] ?? ''] ?? array_keys(Fiscal::TYPES);
        $qb = Invoice::where('kind', 'RECEBER')->whereIn('docType', !empty($q['type']) && in_array($q['type'], $types) ? [$q['type']] : $types)
            ->where($this->companyScope($r));
        if (($q['arch'] ?? null) !== '1') $qb->where('archived', false);
        if (!empty($q['contactId'])) $qb->where('contactId', (int) $q['contactId']);
        if (!empty($q['from'])) $qb->where('issueDate', '>=', $q['from']);
        if (!empty($q['to'])) $qb->where('issueDate', '<=', $q['to']);
        if (($s = trim((string) ($q['q'] ?? ''))) !== '') {
            $qb->where(fn ($w) => $w->where('docNumber', 'like', "%$s%")->orWhere('counterparty', 'like', "%$s%")->orWhere('notes', 'like', "%$s%")
                ->orWhere('vref', 'like', "%$s%")->orWhereHas('lines', fn ($l) => $l->where('description', 'like', "%$s%")));
        }
        $lc = Billing::lateConfig();
        $rows = $qb->with(['company:id,name', 'contact:id,name,cativoPct'])->orderByDesc('issueDate')->orderByDesc('id')->limit(500)->get()->map(fn ($i) => $this->rowView($i, $lc));
        if (!empty($q['state'])) $rows = $rows->filter(fn ($i) => $i->state === $q['state'])->values();
        return $rows;
    }

    public function show(int $id)
    {
        $inv = Render::load($id) ?? abort(404, 'Documento não encontrado.');
        $lines = $inv->lines->count() ? $inv->lines : collect([(object) ['id' => 0, 'pos' => 0, 'code' => null, 'description' => $inv->description ?: "Serviços — {$inv->docNumber}", 'qty' => 1,
            'price' => $inv->subtotal ?? $inv->amount, 'discPct' => 0, 'ivaRate' => $inv->ivaRate, 'net' => $inv->subtotal ?? $inv->amount, 'tax' => $inv->ivaAmount]]);
        if (!$inv->lines->count()) $inv->setRelation('lines', $lines);
        $v = Render::view($inv);
        $this->rowView($inv, Billing::lateConfig());
        $inv->setAttribute('view', ['by' => $v['by'], 'net' => $v['net'], 'tax' => $v['tax'], 'total' => $v['total'], 'ret' => $v['ret'], 'cativo' => $v['cativo'], 'recv' => $v['recv']]);
        $inv->setAttribute('qr', (Fiscal::TYPES[$inv->docType]['fisc'] ?? false) && $inv->hash ? Render::qrText($inv) : null);
        $inv->setAttribute('link', $inv->draft ? null : Notify::docLink($inv->id));
        return $inv;
    }

    public function store(Request $r)
    {
        return response()->json(Billing::saveDocument($this->normalizeDoc($r->validate($this->docRules())), $r->user()), 201);
    }

    public function update(Request $r, int $id)
    {
        return Billing::saveDocument($this->normalizeDoc($r->validate($this->docRules())), $r->user(), $id);
    }

    public function destroy(int $id)
    {
        $inv = Invoice::find($id);
        if (!$inv || !$inv->draft) abort(409, 'Só rascunhos podem ser apagados.');
        $inv->lines()->delete();
        $inv->delete();
        return response()->noContent();
    }

    public function emit(Request $r, int $id)
    {
        $inv = DB::transaction(function () use ($id, $r) {
            $d = Invoice::with(['lines', 'contact'])->find($id);
            if (!$d || !$d->draft) abort(404, 'Rascunho não encontrado.');
            Billing::validateForEmit(['docType' => $d->docType, 'transport' => $d->transport, 'delivery' => $d->delivery], $d->contact,
                $d->lines->map(fn ($l) => ['qty' => $l->qty, 'price' => $l->price])->all());
            return Billing::emitTx($id, $r->user());
        });
        if (Fiscal::TYPES[$inv->docType]['fisc'] && $inv->docType !== 'NC') Notify::later($inv->id, 'EMITIDA');
        return $inv;
    }

    public function cancel(Request $r, int $id) { return Billing::cancelDocument($id, $r->user()) ?: ['ok' => true]; }

    public function printed(int $id)
    {
        $inv = Invoice::findOrFail($id);
        $inv->increment('printCount');
        return ['printCount' => $inv->fresh()->printCount];
    }

    public function bulk(Request $r)
    {
        $d = $r->validate(['op' => ['required', Rule::in(['arch', 'unarch', 'del'])], 'ids' => 'required|array|min:1|max:500', 'ids.*' => 'integer']);
        if ($d['op'] === 'del') {
            $ids = Invoice::whereIn('id', $d['ids'])->where('draft', true)->pluck('id');
            \App\Models\InvoiceLine::whereIn('invoiceId', $ids)->delete();
            return ['affected' => Invoice::whereIn('id', $ids)->delete()];
        }
        return ['affected' => Invoice::whereIn('id', $d['ids'])->update(['archived' => $d['op'] === 'arch'])];
    }

    public function convert(Request $r, int $id)
    {
        $o = Invoice::with(['lines' => fn ($q) => $q->orderBy('pos')])->find($id);
        if (!$o || $o->draft || $o->cancelledAt || !in_array($o->docType, ['PF', 'EN']) || $o->convertedTo) abort(409, 'Só pró-formas e encomendas emitidas e por converter.');
        $today = Rules::today();
        $d = Billing::saveDocument(['docType' => 'FT', 'companyId' => $o->companyId, 'contactId' => $o->contactId, 'issueDate' => $today, 'terms' => '30 dias', 'serie' => (string) $today->year,
            'vref' => $o->vref, 'place' => $o->place, 'notes' => "Origem: {$o->docNumber}", 'gdiscPct' => $o->gdiscPct, 'retPct' => $o->retPct, 'originId' => $o->id, 'emit' => false,
            'lines' => $o->lines->map(fn ($l) => ['code' => $l->code, 'description' => $l->description, 'qty' => $l->qty, 'price' => $l->price, 'discPct' => $l->discPct, 'ivaRate' => $l->ivaRate, 'itemId' => $l->itemId])->all()], $r->user());
        return response()->json($d, 201);
    }

    // ---------- notificações ----------
    public function notify(Request $r, int $id)
    {
        $d = $r->validate(['event' => ['sometimes', Rule::in(Notify::EVENTS)], 'channels' => 'sometimes|array', 'channels.*' => Rule::in(['whatsapp', 'email'])]);
        $sent = Notify::invoice($id, $d['event'] ?? 'EMITIDA', true, $d['channels'] ?? null);
        if (!$sent) abort(422, 'Nada enviado: o cliente não tem WhatsApp/email válido, os canais estão desligados ou o documento não é uma factura emitida.');
        return $sent;
    }

    public function docNotifications(int $id) { return Notification::where('invoiceId', $id)->orderByDesc('id')->limit(50)->get(); }

    public function notifications(Request $r)
    {
        return Notification::with('invoice:id,docNumber,counterparty')->when($r->query('status'), fn ($q, $s) => $q->where('status', (string) $s))->orderByDesc('id')->limit(200)->get();
    }

    public function notifyConfig() { return Notify::config() + ['providers' => Notify::providers(), 'allEvents' => Notify::EVENTS]; }

    public function saveNotifyConfig(Request $r)
    {
        $d = $r->validate(['events' => 'sometimes|array', 'events.*' => Rule::in(Notify::EVENTS), 'dueDays' => 'sometimes|integer|min:1|max:30', 'whatsapp' => 'sometimes|boolean', 'email' => 'sometimes|boolean']);
        return Notify::saveConfig($d) + ['providers' => Notify::providers(), 'allEvents' => Notify::EVENTS];
    }

    public function runNotifications() { return Notify::runDue(true); }

    public function testNotify(Request $r)
    {
        $d = $r->validate(['channel' => ['required', Rule::in(['whatsapp', 'email'])], 'to' => 'required|string|min:3|max:255']);
        return Notify::test($d['channel'], $d['to']);
    }

    /** Página pública (ligação enviada ao cliente): token assinado, só leitura. Sem sessão: o tenant vem do token. */
    public function publicDoc(string $token)
    {
        $t = Notify::verifyDocToken($token);
        $html = null;
        if ($t) {
            Tenancy::set($t[0]);
            try {
                $inv = Render::load($t[1]);
                if ($inv && !$inv->draft) {
                    $open = max(0, Accounting::r2($inv->amount - $inv->paidAmount));
                    $late = !$inv->cancelledAt && !$inv->paidAt && (Fiscal::TYPES[$inv->docType]['pay'] ?? false) ? Billing::lateOf($open, $inv->dueDate, Billing::lateConfig()) : null;
                    $html = Render::html($inv, true, $late);
                }
            } finally { Tenancy::set(null); }
        }
        if (!$html) return response('Documento não encontrado ou ligação expirada.', 404)->header('Content-Type', 'text/plain');
        return response($html)->withHeaders(['Content-Type' => 'text/html; charset=utf-8', 'Referrer-Policy' => 'no-referrer', 'X-Robots-Tag' => 'noindex',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; img-src data:; script-src 'unsafe-inline'"]);
    }

    // ---------- clientes e catálogo ----------
    private function contactRules(bool $p): array
    {
        $req = $p ? 'sometimes' : 'required';
        return ['companyId' => [$req, 'integer', $this->ex('companies')], 'name' => "$req|string|max:255", 'nif' => 'nullable|string|max:50', 'email' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:50', 'whatsapp' => 'nullable|string|max:50', 'address' => 'nullable|string|max:500',
            'kind' => ['sometimes', Rule::in(['Cliente', 'Fornecedor', 'Cliente e fornecedor'])], 'cativoPct' => ['sometimes', Rule::in([0, 50, 100])],
            'notifyEmail' => 'sometimes|boolean', 'notifyWhatsapp' => 'sometimes|boolean', 'active' => 'sometimes|boolean'];
    }

    public function contacts(Request $r)
    {
        $q = trim((string) $r->query('q'));
        $rows = Contact::where($this->companyScope($r))->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('name', 'like', "%$q%")->orWhere('nif', 'like', "%$q%")
            ->orWhere('email', 'like', "%$q%")->orWhere('phone', 'like', "%$q%")))->orderBy('name')->limit(500)->get();
        $debt = Invoice::where('kind', 'RECEBER')->where('draft', false)->whereNull('cancelledAt')->whereNull('paidAt')->whereIn('contactId', $rows->pluck('id'))
            ->whereIn('docType', Fiscal::SALES_TYPES)->get(['contactId', 'amount', 'paidAmount'])->groupBy('contactId')
            ->map(fn ($g) => Accounting::r2($g->sum(fn ($i) => $i->amount - $i->paidAmount)));
        return $rows->each(fn ($c) => $c->setAttribute('debt', $debt[$c->id] ?? 0));
    }

    public function storeContact(Request $r) { return response()->json(Contact::create($r->validate($this->contactRules(false))), 201); }

    public function updateContact(Request $r, int $id)
    {
        $c = Contact::findOrFail($id);
        $c->update($r->validate($this->contactRules(true)));
        return $c;
    }

    public function destroyContact(int $id)
    {
        $c = Contact::findOrFail($id);
        if (Invoice::where('contactId', $id)->exists()) abort(409, 'Contacto com documentos: desactive-o em vez de apagar.');
        $c->delete();
        return response()->noContent();
    }

    private function itemRules(bool $p): array
    {
        $req = $p ? 'sometimes' : 'required';
        return ['companyId' => [$req, 'integer', $this->ex('companies')], 'ref' => 'nullable|string|max:40', 'description' => "$req|string|max:255", 'kind' => ['sometimes', Rule::in(['Produto', 'Serviço'])],
            'unit' => 'sometimes|string|max:20', 'price' => 'sometimes|numeric|min:0', 'ivaRate' => 'sometimes|numeric|min:0|max:100', 'active' => 'sometimes|boolean'];
    }

    public function items(Request $r)
    {
        $q = trim((string) $r->query('q'));
        return Item::where($this->companyScope($r))->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('description', 'like', "%$q%")->orWhere('ref', 'like', "%$q%")))->orderBy('ref')->limit(500)->get();
    }

    public function storeItem(Request $r)
    {
        $d = $r->validate($this->itemRules(false));
        $d['ref'] = $d['ref'] ?? 'IT' . str_pad(Item::where('companyId', $d['companyId'])->count() + 1, 3, '0', STR_PAD_LEFT);
        if (Item::where('companyId', $d['companyId'])->where('ref', $d['ref'])->exists()) abort(409, 'Já existe um item com esta referência.');
        return response()->json(Item::create($d), 201);
    }

    public function updateItem(Request $r, int $id)
    {
        $i = Item::findOrFail($id);
        $i->update($r->validate($this->itemRules(true)));
        return $i;
    }

    public function destroyItem(int $id)
    {
        Item::findOrFail($id)->delete();
        return response()->noContent();
    }

    public function companyFiscal(Request $r, int $id)
    {
        $c = Company::findOrFail($id);
        $c->update($r->validate(['nif' => 'nullable|string|max:50', 'address' => 'nullable|string|max:255', 'email' => 'nullable|string|max:255', 'phone' => 'nullable|string|max:50',
            'website' => 'nullable|string|max:120', 'iban' => 'nullable|string|max:60', 'ivaRegime' => ['sometimes', Rule::in(array_keys(Fiscal::REGIMES))]]));
        return $c;
    }

    // ---------- conformidade AGT ----------
    public function compliance(Request $r)
    {
        $companyId = $this->companyScope($r)['companyId'] ?? null;
        $scope = $companyId ? ['companyId' => $companyId] : [];
        $co = $companyId ? Company::find($companyId) : Company::orderBy('id')->first();
        $reg = $co?->ivaRegime ?: 'geral'; $out = [];
        $add = function ($id, $t, $s, $d, $l) use (&$out) { $out[] = ['id' => $id, 't' => $t, 's' => $s, 'd' => $d, 'l' => $l]; };
        $fiscTypes = array_keys(array_filter(Fiscal::TYPES, fn ($t) => $t['fisc']));
        $emitted = Invoice::where($scope)->where('kind', 'RECEBER')->where('draft', false)->whereIn('docType', $fiscTypes)->with('lines')->get();

        $okE = $co && $co->name && strlen(trim((string) $co->nif)) >= 9;
        $add('emit', 'Identificação do emitente', $okE ? 'ok' : 'fail', $okE ? 'Nome e NIF da empresa preenchidos.' : 'Falta o NIF da empresa (mínimo 9 dígitos). É obrigatório em todas as facturas.', 'Dec. Pres. 71/25, art. 10.º n.º 1 a)');
        $noNif = $emitted->filter(fn ($d) => strlen(trim((string) $d->clientNif)) < 9);
        $add('nifcli', 'NIF do cliente nas facturas emitidas', $noNif->count() ? 'warn' : 'ok', $noNif->count() ? "{$noNif->count()} documento(s) sem NIF do cliente. É obrigatório quando o cliente actua como empresa ou profissional." : 'Todos os documentos emitidos têm NIF do cliente.', 'Dec. Pres. 71/25, art. 10.º n.º 1 a)');

        $g = []; $gaps = [];
        foreach ($emitted->filter(fn ($d) => $d->hash) as $d) {
            if (preg_match('/^(\S+) (\S+)\/(\d+)$/', $d->docNumber, $m)) $g["{$d->companyId}|{$m[1]} {$m[2]}"][] = ['n' => (int) $m[3], 'date' => $d->issueDate, 'k' => "{$m[1]} {$m[2]}"];
        }
        foreach ($g as $a) {
            usort($a, fn ($x, $y) => $x['n'] <=> $y['n']);
            foreach ($a as $i => $x) {
                if ($x['n'] !== $i + 1) $gaps[] = "{$x['k']}/" . ($i + 1) . ' em falta';
                elseif ($i && $x['date'] < $a[$i - 1]['date']) $gaps[] = "{$x['k']}/{$x['n']} com data anterior à do documento {$a[$i - 1]['n']}";
            }
        }
        $add('seq', 'Numeração sequencial e cronológica', $gaps ? 'fail' : 'ok', $gaps ? implode('; ', array_slice($gaps, 0, 3)) : 'Sem saltos na numeração e datas por ordem cronológica em todas as séries.', 'Dec. Pres. 71/25, art. 10.º n.º 1 b)');

        $bad = [];
        foreach ($emitted->filter(fn ($d) => $d->hash)->groupBy(fn ($d) => "{$d->companyId}|{$d->docType}") as $a) $bad = array_merge($bad, Fiscal::auditChain($a));
        $add('hash', 'Integridade da cadeia de hash', $bad ? 'fail' : 'ok', $bad ? 'Alteração detectada em: ' . implode(', ', array_slice($bad, 0, 3)) . '. Um sistema real bloqueia a emissão até haver relatório técnico.' : 'Cada documento está ligado ao anterior e nenhum foi alterado depois de emitido.', 'Dec. Pres. 71/25, art. 22.º (pista de auditoria)');

        $late = Invoice::where($scope)->where('draft', true)->whereIn('docType', $fiscTypes)->get(['issueDate'])->filter(fn ($d) => Fiscal::workDays($d->issueDate, Rules::today()) > 5);
        $add('prazo', 'Prazo de emissão (5.º dia útil)', $late->count() ? 'warn' : 'ok', $late->count() ? "{$late->count()} rascunho(s) com data há mais de 5 dias úteis. Emitir fora de prazo é punível com 0,2% do valor." : 'Sem rascunhos fora do prazo de emissão.', 'Dec. Pres. 71/25, art. 8.º n.º 1 e art. 35.º n.º 10');

        $zero = $emitted->contains(fn ($d) => $d->lines->contains(fn ($l) => (float) $l->ivaRate === 0.0));
        $add('isen', 'Motivo legal nas isenções', $zero && $reg === 'geral' ? 'warn' : 'ok', $zero && $reg === 'geral' ? 'Há linhas isentas (0%). Confirme o motivo legal; o documento indica «motivo legal conforme Código do IVA».' : 'Sem linhas isentas, ou regime que não liquida IVA.', 'Dec. Pres. 71/25, art. 10.º n.º 1 f)');
        $withTax = $reg !== 'geral' ? $emitted->filter(fn ($d) => $d->ivaAmount > 0) : collect();
        $add('regime', 'Regime de IVA coerente com as facturas', $withTax->count() ? 'fail' : 'ok', $withTax->count() ? "{$withTax->count()} documento(s) com IVA liquidado, mas só o Regime Geral liquida IVA na factura." : 'Regime configurado: ' . Fiscal::REGIMES[$reg] . '.', 'Código do IVA, art. 34.º n.º 4');

        $cancelled = $emitted->filter(fn ($d) => $d->cancelledAt && $d->docType !== 'NC'); $ncs = $emitted->where('docType', 'NC')->pluck('refNumber')->all();
        $noNC = $cancelled->filter(fn ($d) => !in_array($d->docNumber, $ncs));
        $add('nc', 'Anulação apenas por nota de crédito', $noNC->count() ? 'fail' : 'ok', $noNC->count() ? "{$noNC->count()} documento(s) cancelado(s) sem nota de crédito." : ($cancelled->count() ? "{$cancelled->count()} documento(s) anulado(s), todos com nota de crédito." : 'Nenhum documento anulado.'), 'Dec. Pres. 71/25, art. 8.º n.º 4');

        $unb = JournalLine::select('entryId', DB::raw('SUM(debit) as d'), DB::raw('SUM(credit) as c'))->groupBy('entryId')->get()->filter(fn ($s) => Accounting::r2($s->d) !== Accounting::r2($s->c));
        $add('acct', 'Lançamentos equilibrados', $unb->count() ? 'fail' : 'ok', $unb->count() ? "{$unb->count()} lançamento(s) com débitos diferentes de créditos." : 'Todos os lançamentos respeitam as partidas dobradas.', 'PGC — Decreto n.º 82/01');
        $cat = Contact::where($scope)->where('cativoPct', '>', 0)->get(['name', 'cativoPct']);
        $add('cativo', 'IVA cativo', 'info', $cat->count() ? "{$cat->count()} cliente(s) retêm IVA: " . $cat->take(3)->map(fn ($c) => "{$c->name} ({$c->cativoPct}%)")->implode(', ') . '. O documento mostra o valor retido e o valor a receber.' : 'Nenhum cliente com retenção de IVA. Estado, petrolíferas, bancos, seguradoras e operadoras de telecomunicações retêm 100% ou 50%.', 'Código do IVA, art. 21.º; Dec. Pres. 71/25, art. 6.º n.º 3');
        $dd = Rules::daysBetween('2027-01-01', Rules::today());
        $add('einv', 'Facturação electrónica obrigatória', $reg === 'exclusao' ? 'info' : 'warn', $reg === 'exclusao' ? 'Opcional para o Regime de Exclusão, mediante pedido à AGT.' : ($dd > 0 ? "Faltam $dd dias para 1 de Janeiro de 2027, data em que passa a ser obrigatória" : 'Obrigatória desde 1 de Janeiro de 2027') . ' para os regimes Geral e Simplificado. Exige software validado pela AGT e comunicação em tempo real.', 'Dec. Pres. 71/25, arts. 16.º e 17.º; Dec. Exec. 683/25');
        $add('sw', 'Software validado pela AGT', 'info', 'Este módulo ainda não está validado pela AGT e não comunica facturas à AGT. Para emitir facturas com valor fiscal, use um programa da lista de software validado publicada pela AGT.', 'Dec. Pres. 71/25, art. 7.º n.º 1 e art. 10.º n.º 1 j)');
        $sc = array_values(array_filter($out, fn ($x) => $x['s'] !== 'info')); $ok = count(array_filter($sc, fn ($x) => $x['s'] === 'ok'));
        return ['checks' => $out, 'score' => $sc ? (int) round(100 * $ok / count($sc)) : 100, 'regime' => $reg, 'deadlines' => Fiscal::deadlines()];
    }

    /** Simulação de adulteração SEM tocar nos dados: recalcula o hash com +1,00 Kz. */
    public function tamper(int $id)
    {
        $d = Invoice::whereNotNull('hash')->find($id) ?? abort(404, 'Documento fiscal emitido não encontrado.');
        $total = (float) ($d->grossTotal ?? $d->amount);
        $exp = Fiscal::docHash($d->issueDate, $d->docNumber, $total + 1, $d->prevHash);
        return ['docNumber' => $d->docNumber, 'originalTotal' => $total, 'alteredTotal' => Accounting::r2($total + 1), 'storedHash' => $d->hash, 'hashIfAltered' => $exp, 'detected' => $exp !== $d->hash];
    }

    // ---------- apuramento do IVA ----------
    public function iva(Request $r)
    {
        if (!preg_match('/^(\d{4})-(\d{2})$/', (string) $r->query('month'), $m)) abort(400, 'Indique month=AAAA-MM.');
        $y = (int) $m[1]; $mo = (int) $m[2];
        $from = Carbon::create($y, $mo, 1, 0, 0, 0, 'UTC'); $to = $from->copy()->endOfMonth()->startOfDay();
        $scope = $this->companyScope($r);
        $co = $scope ? Company::find($scope['companyId']) : Company::orderBy('id')->first();
        $reg = $co?->ivaRegime ?: 'geral';
        $rows = Invoice::where($scope)->where('kind', 'RECEBER')->where('draft', false)->whereIn('docType', [...Fiscal::SALES_TYPES, 'NC'])
            ->whereBetween('issueDate', [$from, $to])->with(['lines', 'company:id,name'])->orderBy('issueDate')->get();
        $by = []; $liq = 0; $cativo = 0;
        $docs = $rows->map(function ($d) use (&$by, &$liq, &$cativo) {
            $sg = $d->docType === 'NC' ? -1 : 1;
            foreach ($d->lines as $l) {
                $k = (string) (float) $l->ivaRate; $by[$k] ??= ['base' => 0, 'tax' => 0];
                $by[$k]['base'] = Accounting::r2($by[$k]['base'] + $sg * $l->net); $by[$k]['tax'] = Accounting::r2($by[$k]['tax'] + $sg * $l->tax);
            }
            $liq += $sg * $d->ivaAmount; $cativo += $sg * $d->cativoAmount;
            return ['id' => $d->id, 'docNumber' => $d->docNumber, 'counterparty' => $d->counterparty, 'net' => Accounting::r2($sg * $d->subtotal), 'tax' => Accounting::r2($sg * $d->ivaAmount), 'cativo' => Accounting::r2($sg * $d->cativoAmount)];
        });
        $t = Accounting::taxes(['companyId' => $scope['companyId'] ?? null, 'from' => $from, 'to' => $to]);
        $ded = $t['iva']['dedutivel'];
        $received = (float) Receipt::whereBetween('paymentDate', [$from, $to])->whereHas('invoice', fn ($q) => $q->where($scope)->where('kind', 'RECEBER'))->sum('amount');
        $due = Fiscal::lastWorkingDay($y, $mo)->toDateString();
        if ($reg === 'geral') $result = ['liquidado' => Accounting::r2($liq), 'dedutivel' => Accounting::r2($ded), 'apagar' => Accounting::r2($liq - $ded)];
        elseif ($reg === 'simplificado') { $s7 = Accounting::r2($received * 0.07); $d10 = Accounting::r2(max(0, $ded) * 0.10); $result = ['recebido' => $received, 'iva7' => $s7, 'deducao10' => $d10, 'apagar' => Accounting::r2(max(0, $s7 - $d10))]; }
        else $result = ['apagar' => 0];
        return ['month' => $r->query('month'), 'regime' => $reg, 'by' => $by, 'docs' => $docs->slice(-50)->reverse()->values(), 'cativo' => Accounting::r2($cativo), 'result' => $result,
            'due' => $due, 'daysLeft' => Rules::daysBetween($due, Rules::today())];
    }

    public function lateConfig() { return Billing::lateConfig(); }

    public function saveLateConfig(Request $r)
    {
        return Billing::saveLateConfig($r->validate(['feePct' => 'sometimes|numeric|min:0|max:100', 'monthlyPct' => 'sometimes|numeric|min:0|max:100', 'graceDays' => 'sometimes|integer|min:0|max:365']));
    }

    public function meta()
    {
        return ['types' => collect(Fiscal::TYPES)->map(fn ($v) => ['name' => $v['n'], 'fisc' => $v['fisc'], 'mod' => $v['mod']]), 'terms' => Fiscal::TERMS, 'regimes' => Fiscal::REGIMES];
    }
}
