<?php

namespace App\Services;

use App\Models\Invoice;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Carbon\Carbon;

/** Vista do documento (valores gravados) + página HTML pública/imprimível com QR code (porta de render.js). */
class Render
{
    public static function e($s): string { return htmlspecialchars((string) ($s ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
    private static function br($d): string { return $d ? Carbon::parse($d)->format('d/m/Y') : '—'; }
    private static function kz($n): string { return Notify::kz($n); }

    public static function load(int $id): ?Invoice
    {
        return Invoice::with(['company', 'contact', 'lines' => fn ($q) => $q->orderBy('pos'), 'receipts' => fn ($q) => $q->orderBy('id')])->find($id);
    }

    /** Modelo de apresentação a partir dos valores gravados. */
    public static function view(Invoice $inv): array
    {
        $T = Fiscal::TYPES[$inv->docType] ?? ['n' => 'Documento', 'fisc' => false];
        $by = [];
        foreach ($inv->lines as $l) {
            $k = (string) (float) $l->ivaRate;
            $by[$k] ??= ['base' => 0, 'tax' => 0];
            $by[$k]['base'] = Fiscal::r2($by[$k]['base'] + $l->net); $by[$k]['tax'] = Fiscal::r2($by[$k]['tax'] + $l->tax);
        }
        return ['T' => $T, 'sg' => ($T['neg'] ?? false) ? -1 : 1, 'by' => $by, 'net' => (float) $inv->subtotal, 'tax' => (float) $inv->ivaAmount,
            'total' => $inv->grossTotal !== null ? (float) $inv->grossTotal : (float) $inv->amount, 'ret' => (float) $inv->retAmount,
            'cativo' => (float) $inv->cativoAmount, 'cativoPct' => (float) $inv->cativoPct, 'recv' => (float) $inv->amount];
    }

    public static function qrText(Invoice $inv): string
    {
        return 'DOC|' . ($inv->company->nif ?? '') . '|' . ($inv->clientNif ?? '') . '|' . $inv->docNumber . '|'
            . number_format((float) ($inv->grossTotal ?? $inv->amount), 2, '.', '') . '|' . substr((string) $inv->hash, 0, 8);
    }

    private static function qrSvg(string $text): string
    {
        $svg = (new Writer(new ImageRenderer(new RendererStyle(92, 0), new SvgImageBackEnd())))->writeString($text);
        return preg_replace('/^<\?xml[^>]*\?>\s*/', '', $svg);
    }

    public static function html(Invoice $inv, bool $original = true, ?array $late = null): string
    {
        $e = fn ($s) => self::e($s); $kz = fn ($n) => self::kz($n);
        $v = self::view($inv); $T = $v['T']; $co = $inv->company; $cu = $inv->contact; $sg = $v['sg'];
        $lines = '';
        foreach ($inv->lines as $l) {
            $g = $l->qty * $l->price; $val = $g - Fiscal::r2($g * $l->discPct / 100);
            $lines .= '<tr><td>' . $e($l->code) . '</td><td>' . $e($l->description) . '</td><td class="r">' . (float) $l->qty . '</td><td class="r">' . $kz($l->price)
                . '</td><td class="r">' . ($l->discPct ? (float) $l->discPct . '%' : '—') . '</td><td class="r">' . ($l->ivaRate ? (float) $l->ivaRate . '%' : 'Isento')
                . '</td><td class="r">' . $kz($sg * $val) . '</td></tr>';
        }
        $tx = ''; $keys = array_keys($v['by']); rsort($keys, SORT_NUMERIC);
        foreach ($keys as $k) {
            $b = $v['by'][$k];
            $tx .= '<div><span>' . ((float) $k ? "IVA $k% sobre " . $kz($sg * $b['base']) : 'Isento (base ' . $kz($sg * $b['base']) . ')') . '</span><span>' . $kz($sg * $b['tax']) . '</span></div>';
        }
        $paid = '';
        foreach ($inv->receipts as $r) $paid .= '<tr><td>' . $e($r->docNumber) . '</td><td>' . self::br($r->paymentDate) . '</td><td>' . $e($r->paymentMethod) . '</td><td class="r">' . $kz($r->amount) . '</td></tr>';
        $qr = ($T['fisc'] ?? false) && $inv->hash ? self::qrSvg(self::qrText($inv)) : '';
        $tr = $inv->transport ?? []; $dl = $inv->delivery ?? [];
        $extra = $inv->docType === 'GT' && $tr
            ? '<p><b>Matrícula:</b> ' . $e($tr['plate'] ?? '—') . ' · <b>Data de carga:</b> ' . self::br($tr['loadDate'] ?? null) . '</p><p><b>Carga:</b> ' . $e($tr['from']['addr'] ?? '') . ' ' . $e($tr['from']['city'] ?? '') . ' · <b>Entrega:</b> ' . $e($tr['to']['addr'] ?? '') . ' ' . $e($tr['to']['city'] ?? '') . '</p>'
            : ($inv->docType === 'EN' && $dl ? '<p><b>Data de entrega:</b> ' . self::br($dl['date'] ?? null) . (!empty($dl['place']) ? ' · <b>Local:</b> ' . $e($dl['place']) : '') . '</p>' : '');
        $reg = $co->ivaRegime !== 'geral' && ($T['fisc'] ?? false) ? 'IVA — ' . ($co->ivaRegime === 'exclusao' ? 'Regime de Exclusão' : 'Regime Simplificado') : '';
        $note = 'Programa de demonstração, não validado pela AGT. Sem validade fiscal até à certificação do software.';
        $notes = array_filter([$reg, isset($v['by']['0']) ? 'Isento: motivo legal conforme Código do IVA' : '', $inv->docType === 'AF' ? 'Auto-Facturação' : '']);
        $via = ($T['fisc'] ?? false) && $inv->hash ? '<b>' . ($original && !$inv->printCount ? 'Original' : '2.ª via, em conformidade com a original') . '</b><br>' : '';

        $h = '<!doctype html><html lang="pt-AO"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . $e($T['n']) . ' ' . $e($inv->docNumber) . '</title>
<style>body{margin:0;background:#eef1f6;font:13px/1.45 system-ui,Segoe UI,Arial,sans-serif;color:#1f1e1a}.bar{max-width:820px;margin:16px auto 0;text-align:right}.bar button{padding:8px 16px;border:0;border-radius:8px;background:#2457d6;color:#fff;font-weight:600;cursor:pointer}
.p{max-width:820px;margin:12px auto 32px;background:#fff;border:1px solid #d3d1c7;padding:28px}h3{margin:0}.h{display:flex;justify-content:space-between;gap:16px;flex-wrap:wrap;border-bottom:2px solid #1f1e1a;padding-bottom:12px;margin-bottom:12px}.t{text-align:right}
table{width:100%;border-collapse:collapse;margin:10px 0}th{text-align:left;border-bottom:1px solid #1f1e1a;padding:6px 4px;font-size:12px}td{padding:6px 4px;border-bottom:1px solid #e5e3da;vertical-align:top}.r{text-align:right}
.s{margin-left:auto;max-width:340px}.s div{display:flex;justify-content:space-between;gap:12px;padding:2px 0}.s .tot{border-top:2px solid #1f1e1a;margin-top:4px;padding-top:6px;font-weight:700;font-size:15px}
.f{display:flex;gap:16px;align-items:center;border-top:1px solid #d3d1c7;padding-top:12px;margin-top:12px;flex-wrap:wrap}.m{flex:1;min-width:200px;font-size:11px;color:#444;word-break:break-all}.q{width:100px;height:100px}
@media print{body{background:#fff}.bar{display:none}.p{border:0;margin:0;max-width:none}}</style></head><body>
<div class="bar"><button onclick="window.print()">Imprimir / Guardar PDF</button></div>
<article class="p"><header class="h"><div><h3>' . $e($co->name) . '</h3><div>NIF ' . $e($co->nif ?: '—') . '</div><div>' . $e($co->address) . '</div><div>' . $e($co->email) . ' ' . $e($co->phone) . '</div></div>
<div class="t"><b style="font-size:17px">' . $e($T['n']) . '</b><br>' . ($inv->draft ? 'Rascunho' : $e($inv->docNumber)) . '<br>' . $via . 'Data: ' . self::br($inv->issueDate)
            . ($inv->docType !== 'GT' ? '<br>' . ($inv->docType === 'PF' ? 'Válido até' : 'Vencimento') . ': ' . self::br($inv->dueDate) : '')
            . ($inv->vref ? '<br>V/Ref.: ' . $e($inv->vref) : '') . ($inv->refNumber && $inv->docType === 'NC' ? '<br>Anula: ' . $e($inv->refNumber) : '') . '</div></header>
<section><b>Cliente:</b> ' . $e($inv->counterparty) . ' · NIF ' . $e($inv->clientNif ?: '—') . ($cu?->address ? ' · ' . $e($cu->address) : '') . ($inv->place ? '<br><b>Local:</b> ' . $e($inv->place) : '') . '</section>' . $extra . '
<table><thead><tr><th>Cód.</th><th>Descrição</th><th class="r">Qtd.</th><th class="r">Preço</th><th class="r">Desc.</th><th class="r">IVA</th><th class="r">Valor</th></tr></thead><tbody>' . $lines . '</tbody></table>'
            . ($notes ? '<p><b>' . implode(' · ', array_map($e, $notes)) . '</b></p>' : '') . '
<section class="s">' . ($inv->gdiscPct ? '<div><span>Desconto global (' . (float) $inv->gdiscPct . '%)</span><span>incluído</span></div>' : '') . '<div><span>Total sem IVA</span><span>' . $kz($sg * $v['net']) . '</span></div>' . $tx
            . ($v['ret'] ? '<div><span>Retenção (' . (float) $inv->retPct . '%)</span><span>-' . $kz($sg * $v['ret']) . '</span></div>' : '') . '<div class="tot"><span>Total</span><span>' . $kz($sg * $v['total']) . '</span></div>'
            . ($v['cativo'] ? '<div style="color:#7a4b00"><span>IVA cativo retido pelo cliente (' . $v['cativoPct'] . '%)</span><span>-' . $kz($sg * $v['cativo']) . '</span></div><div class="tot"><span>Valor a receber</span><span>' . $kz($sg * $v['recv']) . '</span></div>' : '') . '</section>'
            . ($late && $late['total'] > 0 ? '<p style="border:1px solid #d3d1c7;padding:8px;background:#fff8e6"><b>Documento vencido há ' . $late['days'] . ' dias.</b> Encargos por atraso até hoje (multa ' . $kz($late['fee']) . ' + juros de mora ' . $kz($late['interest']) . '): <b>' . $kz($late['total']) . '</b>. Valor em aberto: ' . $kz($late['base']) . '.</p>' : '')
            . '<p style="font-style:italic">' . ($sg < 0 ? 'Crédito de ' : 'Total por extenso: ') . $e(Fiscal::words($v['total'])) . '.</p>' . ($inv->notes ? '<p><b>Observações:</b> ' . $e($inv->notes) . '</p>' : '')
            . ($paid ? '<table><thead><tr><th colspan="4">Pagamentos</th></tr></thead><tbody>' . $paid . '</tbody></table>' : '')
            . '<footer class="f">' . ($qr ? '<div class="q">' . $qr . '</div>' : '') . '<div class="m">'
            . (($T['fisc'] ?? false) ? ($inv->hash ? 'Hash: ' . $e(substr($inv->hash, 0, 16)) . '…' . $e(substr($inv->hash, -8)) . '<br>' . $note : 'Rascunho: número e hash gerados na emissão.') : 'Documento sem valor fiscal.')
            . ($co->iban ? '<br>IBAN ' . $e($co->iban) : '') . '</div></footer></article></body></html>';
        return $h;
    }
}
