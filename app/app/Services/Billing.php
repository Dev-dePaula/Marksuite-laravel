<?php

namespace App\Services;

use App\Models\{Company, Contact, Invoice, Item, JournalEntry, Movement, Receipt, Setting};
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/** Núcleo de facturação (porta de billing.js + documents.js): emissão, hash, recebimentos, anulação por NC. */
class Billing
{
    public const METHODS = ['transferencia', 'dinheiro', 'multicaixa', 'cheque'];
    private const LATE_KEYS = ['lateFeePct', 'lateInterestMonthly', 'lateGraceDays'];

    public static function bankCode(?string $m): string { return $m === 'dinheiro' ? Accounting::ACC['CAIXA'] : Accounting::ACC['BANCOS']; }

    // ---------- encargos por atraso (configuração) ----------
    public static function lateConfig(): array
    {
        $s = Setting::whereIn('key', self::LATE_KEYS)->pluck('value', 'key');
        $n = fn ($v, $d) => is_numeric($v) && $v >= 0 ? (float) $v : $d;
        return ['feePct' => $n($s['lateFeePct'] ?? null, 0), 'monthlyPct' => $n($s['lateInterestMonthly'] ?? null, 0), 'graceDays' => (int) round($n($s['lateGraceDays'] ?? null, 0))];
    }

    public static function saveLateConfig(array $d): array
    {
        foreach (['feePct' => 'lateFeePct', 'monthlyPct' => 'lateInterestMonthly', 'graceDays' => 'lateGraceDays'] as $k => $key) {
            if (isset($d[$k])) Rules::setSetting($key, $d[$k]);
        }
        return self::lateConfig();
    }

    public static function lateOf($open, $dueDate, array $cfg): array { return Fiscal::lateCharges(['open' => $open, 'dueDate' => $dueDate] + $cfg); }

    // ---------- totais de facturas simples ----------
    public static function computeTotals(array $d): array
    {
        if (isset($d['subtotal'])) {
            $rate = $d['ivaRate'] ?? Accounting::taxSettings()['ivaRate'];
            $sub = Accounting::r2($d['subtotal']); $iva = Accounting::r2($sub * $rate / 100);
            return ['subtotal' => $sub, 'ivaRate' => $rate, 'ivaAmount' => $iva, 'amount' => Accounting::r2($sub + $iva)];
        }
        return ['subtotal' => Accounting::r2($d['amount']), 'ivaRate' => 0, 'ivaAmount' => 0, 'amount' => Accounting::r2($d['amount'])];
    }

    /** Factura simples (Finanças) + lançamento contabilístico, numa transacção. */
    public static function createInvoice(array $in, $user): Invoice
    {
        $t = self::computeTotals($in);
        $issue = isset($in['issueDate']) ? Carbon::parse($in['issueDate']) : Rules::today();
        return DB::transaction(function () use ($in, $t, $issue, $user) {
            $receber = ($in['kind'] ?? 'RECEBER') === 'RECEBER';
            $docNumber = $in['docNumber'] ?? Accounting::nextDocNumber($receber ? 'FT' : 'FC', $issue);
            $inv = Invoice::create(['kind' => $receber ? 'RECEBER' : 'PAGAR', 'docType' => $receber ? 'FT' : 'FC', 'counterparty' => $in['counterparty'],
                'docNumber' => $docNumber, 'companyId' => $in['companyId'], 'dueDate' => $in['dueDate'], 'issueDate' => $issue,
                'clientNif' => $in['clientNif'] ?? null, 'description' => $in['description'] ?? null] + $t);
            $A = Accounting::ACC;
            $lines = $receber
                ? [['code' => $A['CLIENTES'], 'debit' => $t['amount']], ['code' => $A['RECEITA'], 'credit' => $t['subtotal']], ['code' => $A['IVA_LIQUIDADO'], 'credit' => $t['ivaAmount']]]
                : [['code' => $A['DESPESA'], 'debit' => $t['subtotal']], ['code' => $A['IVA_DEDUTIVEL'], 'debit' => $t['ivaAmount']], ['code' => $A['FORNECEDORES'], 'credit' => $t['amount']]];
            Accounting::postEntry(['companyId' => $inv->companyId, 'date' => $issue, 'lines' => $lines, 'source' => 'billing', 'sourceType' => 'invoice',
                'sourceId' => $inv->id, 'user' => $user->name,
                'description' => ($receber ? 'Factura' : 'Factura de fornecedor') . " $docNumber — {$inv->counterparty}"]);
            return $inv;
        });
    }

    /** Recibo + lançamento + movimento de caixa. Usado por /receipts e pela factura-recibo. Corre dentro de transacção. */
    public static function registerPayment(Invoice $inv, array $o): Invoice
    {
        $recv = $inv->kind === 'RECEBER';
        $late = Accounting::r2($o['lateAmount'] ?? 0);
        $amount = $o['amount']; $date = Carbon::parse($o['date'])->startOfDay();
        $receipt = Receipt::create(['docNumber' => Accounting::nextDocNumber($recv ? 'RC' : 'PG', $date), 'invoiceId' => $inv->id, 'amount' => $amount,
            'lateAmount' => $late, 'paymentDate' => $date, 'paymentMethod' => $o['method']]);
        $bank = self::bankCode($o['method']); $A = Accounting::ACC;
        Accounting::postEntry(['companyId' => $inv->companyId, 'date' => $date, 'source' => 'billing', 'sourceType' => 'receipt', 'sourceId' => $receipt->id, 'user' => $o['user']->name,
            'description' => ($recv ? 'Recebimento' : 'Pagamento') . " {$receipt->docNumber} — factura {$inv->docNumber}",
            'lines' => $recv ? [['code' => $bank, 'debit' => Accounting::r2($amount + $late)], ['code' => $A['CLIENTES'], 'credit' => $amount], ['code' => $A['JUROS'], 'credit' => $late]]
                : [['code' => $A['FORNECEDORES'], 'debit' => $amount], ['code' => $bank, 'credit' => $amount]]]);
        Movement::create(['date' => $date, 'description' => ($recv ? 'Recebimento' : 'Pagamento') . " — {$inv->counterparty}", 'companyId' => $inv->companyId,
            'category' => $recv ? 'Recebimentos' : 'Fornecedores', 'amount' => $recv ? Accounting::r2($amount + $late) : -$amount]);
        $paid = Accounting::r2($inv->paidAmount + $amount);
        $inv->update(['paidAmount' => $paid, 'paidAt' => $paid >= $inv->amount ? $date : null]);
        return $inv->fresh();
    }

    /** Regista pagamento/recebimento (total ou parcial). */
    public static function payInvoice(int $id, array $in, $user): Invoice
    {
        $out = DB::transaction(function () use ($id, $in, $user) {
            $inv = Invoice::where('draft', false)->whereNull('cancelledAt')->find($id);
            if (!$inv) abort(404, 'Factura não encontrada.');
            if (in_array($inv->docType, ['NC', 'PF', 'GT', 'EN'])) abort(409, 'Este tipo de documento não tem pagamentos.');
            if ($inv->paidAt) abort(409, 'Factura já liquidada.');
            $open = Accounting::r2($inv->amount - $inv->paidAmount);
            $amount = Accounting::r2($in['amount'] ?? $open);
            if ($amount <= 0 || $amount > $open) abort(422, "Valor inválido: em aberto $open.");
            $when = isset($in['paymentDate']) ? Carbon::parse($in['paymentDate']) : Rules::today();
            $late = (!empty($in['chargeLate']) && $inv->kind === 'RECEBER')
                ? Fiscal::lateCharges(['open' => $amount, 'dueDate' => $inv->dueDate, 'today' => $when] + self::lateConfig())['total'] : 0;
            return self::registerPayment($inv, ['amount' => $amount, 'date' => $when, 'method' => $in['paymentMethod'] ?? 'transferencia', 'user' => $user, 'lateAmount' => $late]);
        });
        if ($out->kind === 'RECEBER') Notify::later($out->id, $out->paidAt ? 'PAGA' : 'PARCIAL');
        return $out;
    }

    // ---------- documentos (rascunho → emitido) ----------
    private static function resolveContact(array $d): ?Contact
    {
        if (!empty($d['contactId'])) return Contact::find($d['contactId']) ?? abort(404, 'Cliente não encontrado.');
        $n = $d['newClient'] ?? null;
        if ($n && !empty($n['name'])) return Contact::create(['companyId' => $d['companyId']] + array_intersect_key($n, array_flip(['name', 'nif', 'email', 'phone', 'whatsapp', 'address'])));
        return null;
    }

    public static function validateForEmit(array $d, ?Contact $contact, array $lines): void
    {
        if (!$contact) abort(400, 'Escolha ou crie um cliente.');
        if (!$lines) abort(400, 'Adicione pelo menos uma linha com descrição.');
        foreach ($lines as $l) if (!(($l['qty'] ?? 0) > 0) || !(($l['price'] ?? 0) > 0)) abort(400, 'Quantidade e preço têm de ser superiores a zero.');
        if ($d['docType'] === 'GT') {
            if (empty($d['transport']['loadDate'])) abort(400, 'Indique a data de carga.');
            if (empty($d['transport']['from']['addr']) || empty($d['transport']['to']['addr'])) abort(400, 'Indique o endereço do local de carga e do local de entrega.');
        }
        if ($d['docType'] === 'EN' && empty($d['delivery']['date'])) abort(400, 'Indique a data de entrega.');
    }

    /** Cria/actualiza um rascunho e, se pedido, emite — numa transacção. */
    public static function saveDocument(array $in, $user, ?int $id = null): Invoice
    {
        $inv = DB::transaction(function () use ($in, $user, $id) {
            $existing = null;
            if ($id) {
                $existing = Invoice::find($id) ?? abort(404, 'Documento não encontrado.');
                if (!$existing->draft) abort(409, 'Documento emitido: é imutável. Corrija com nota de crédito ou cancelamento.');
            }
            $contact = self::resolveContact($in);
            $company = Company::findOrFail($in['companyId']);
            $regime = $company->ivaRegime ?: 'geral';
            $lines = array_values(array_filter($in['lines'], fn ($l) => trim($l['description'] ?? '') !== '' || ($l['price'] ?? 0) > 0 || trim($l['code'] ?? '') !== ''));
            if (!empty($in['emit'])) self::validateForEmit($in, $contact, $lines);
            foreach ($lines as $l) if (trim($l['description'] ?? '') === '') abort(400, 'Todas as linhas precisam de descrição.');

            foreach ($lines as &$l) {
                if (!empty($l['newItem']) && !empty($l['code']) && empty($l['itemId'])) {
                    $dup = Item::where('companyId', $in['companyId'])->where('ref', $l['code'])->first();
                    $l['itemId'] = $dup?->id ?? Item::create(['companyId' => $in['companyId'], 'ref' => $l['code'], 'description' => $l['description'],
                        'kind' => 'Produto', 'price' => $l['price'], 'ivaRate' => $l['ivaRate'] ?? 14])->id;
                }
            }
            unset($l);
            $c = Fiscal::calc(['lines' => $lines, 'gdiscPct' => $in['gdiscPct'] ?? 0, 'retPct' => $in['retPct'] ?? 0, 'cativoPct' => $contact?->cativoPct ?? 0, 'regime' => $regime, 'type' => $in['docType']]);
            $terms = $in['terms'] ?? ($in['docType'] === 'PF' ? 'Pronto pagamento' : '45 dias');
            $issue = Carbon::parse($in['issueDate'])->startOfDay();
            $maxRate = max(0, ...array_column($c['rows'], 'ivaRate') ?: [0]);
            $data = ['kind' => 'RECEBER', 'docType' => $in['docType'], 'serie' => $in['serie'] ?? (string) $issue->year, 'draft' => true, 'companyId' => $in['companyId'],
                'contactId' => $contact?->id, 'counterparty' => $contact ? mb_substr($contact->name, 0, 120) : '—', 'clientNif' => $contact?->nif,
                'issueDate' => $issue, 'dueDate' => Fiscal::calcDue($in['docType'], $issue, $terms), 'terms' => $terms, 'vref' => $in['vref'] ?? null,
                'place' => $in['place'] ?? null, 'notes' => $in['notes'] ?? null, 'gdiscPct' => $in['gdiscPct'] ?? 0, 'retPct' => $in['retPct'] ?? 0,
                'retAmount' => $c['ret'], 'cativoPct' => $c['cativoPct'], 'cativoAmount' => $c['cativo'], 'subtotal' => $c['net'], 'ivaRate' => $maxRate,
                'ivaAmount' => $c['tax'], 'grossTotal' => $c['total'], 'amount' => $c['recv'],
                'transport' => $in['docType'] === 'GT' ? ($in['transport'] ?? []) : null, 'delivery' => $in['docType'] === 'EN' ? ($in['delivery'] ?? []) : null,
                'originId' => $in['originId'] ?? $existing?->originId, 'createdBy' => $existing?->createdBy ?: $user->name, 'description' => $lines[0]['description'] ?? null];
            $lineRows = array_map(fn ($r, $i) => ['pos' => $i, 'code' => $r['code'] ?? null, 'description' => $r['description'], 'qty' => $r['qty'], 'price' => $r['price'],
                'discPct' => $r['discPct'] ?? 0, 'ivaRate' => $r['ivaRate'], 'net' => $r['net'], 'tax' => $r['tax'], 'itemId' => $r['itemId'] ?? null], $c['rows'], array_keys($c['rows']));
            if ($existing) { $existing->lines()->delete(); $existing->update($data); $inv = $existing; }
            else $inv = Invoice::create($data + ['docNumber' => 'Rascunho']);
            foreach ($lineRows as $lr) $inv->lines()->create($lr);
            return !empty($in['emit']) ? self::emitTx($inv->id, $user) : $inv->fresh();
        });
        if (!$inv->draft && $inv->kind === 'RECEBER' && (Fiscal::TYPES[$inv->docType]['fisc'] ?? false) && $inv->docType !== 'NC') Notify::later($inv->id, 'EMITIDA');
        return $inv;
    }

    private static function postSale(Invoice $inv, $user): void
    {
        $A = Accounting::ACC;
        Accounting::postEntry(['companyId' => $inv->companyId, 'date' => $inv->issueDate, 'source' => 'billing', 'sourceType' => 'invoice', 'sourceId' => $inv->id, 'user' => $user->name,
            'description' => Fiscal::TYPES[$inv->docType]['n'] . " {$inv->docNumber} — {$inv->counterparty}",
            'lines' => [['code' => $A['CLIENTES'], 'debit' => $inv->amount], ['code' => $A['RETENCOES'], 'debit' => $inv->retAmount], ['code' => $A['IVA_DEDUTIVEL'], 'debit' => $inv->cativoAmount],
                ['code' => $inv->docType === 'FA' ? $A['ADIANTAMENTOS'] : $A['RECEITA'], 'credit' => Accounting::r2($inv->subtotal)], ['code' => $A['IVA_LIQUIDADO'], 'credit' => Accounting::r2($inv->ivaAmount)]]]);
    }

    /** Emite um rascunho: número por série, hash encadeado e (se fiscal) lançamento. Corre dentro de transacção. */
    public static function emitTx(int $id, $user): Invoice
    {
        $inv = Invoice::find($id);
        if (!$inv || !$inv->draft) abort(404, 'Rascunho não encontrado.');
        $T = Fiscal::TYPES[$inv->docType];
        $docNumber = Accounting::nextDocNumber($inv->docType, $inv->issueDate, $inv->serie);
        $patch = ['docNumber' => $docNumber, 'draft' => false];
        if ($T['fisc']) {
            $eseq = Accounting::bump('ESEQ');
            $prev = Invoice::where('companyId', $inv->companyId)->where('docType', $inv->docType)->whereNotNull('hash')->orderByDesc('eseq')->first();
            $patch['prevHash'] = $prev ? $prev->hash : '0'; $patch['eseq'] = $eseq;
            $patch['hash'] = Fiscal::docHash($inv->issueDate, $docNumber, $inv->grossTotal, $patch['prevHash']);
            if ($sig = Fiscal::sign(Fiscal::signedText($inv->issueDate, $docNumber, $inv->grossTotal, $patch['prevHash']))) {
                $patch['signature'] = $sig['signature']; $patch['signKeyVersion'] = $sig['version'];
            }
        }
        $inv->update($patch);
        $out = $inv->fresh();
        if ($T['fisc'] && $out->docType !== 'NC') self::postSale($out, $user);
        if ($T['autopaid'] ?? false) self::registerPayment($out, ['amount' => $out->amount, 'date' => $out->issueDate, 'method' => 'dinheiro', 'user' => $user]);
        if ($out->originId) Invoice::where('id', $out->originId)->update(['convertedTo' => $docNumber]);
        return $out->fresh();
    }

    /** Anula: documento fiscal → emite nota de crédito (estorna o lançamento); outros → cancelamento simples. */
    public static function cancelDocument(int $id, $user): ?Invoice
    {
        return DB::transaction(function () use ($id, $user) {
            $inv = Invoice::with(['lines' => fn ($q) => $q->orderBy('pos')])->where('draft', false)->whereNull('cancelledAt')->find($id);
            if (!$inv) abort(404, 'Documento não encontrado, em rascunho ou já anulado.');
            if ($inv->docType === 'NC') abort(409, 'Uma nota de crédito não pode ser anulada.');
            if ($inv->paidAmount > 0) abort(409, 'Documento com pagamentos não pode ser anulado.');
            if ($inv->docType === 'FR') abort(409, 'Factura-recibo já paga: emita a devolução por outro meio.');
            $T = Fiscal::TYPES[$inv->docType] ?? ['fisc' => $inv->kind === 'RECEBER'];
            $created = null;
            if ($inv->kind === 'RECEBER' && $T['fisc']) {
                $lines = $inv->lines->count() ? $inv->lines : collect([(object) ['code' => null, 'description' => $inv->description ?: "Anulação de {$inv->docNumber}", 'qty' => 1,
                    'price' => $inv->subtotal ?? $inv->amount, 'discPct' => 0, 'ivaRate' => $inv->ivaRate, 'net' => $inv->subtotal ?? $inv->amount, 'tax' => $inv->ivaAmount, 'itemId' => null]]);
                $today = Rules::today();
                $draft = Invoice::create(['kind' => 'RECEBER', 'docType' => 'NC', 'serie' => (string) $today->year, 'draft' => true, 'docNumber' => 'Rascunho', 'companyId' => $inv->companyId,
                    'contactId' => $inv->contactId, 'counterparty' => $inv->counterparty, 'clientNif' => $inv->clientNif, 'issueDate' => $today, 'dueDate' => $today,
                    'terms' => 'Pronto pagamento', 'notes' => "Anula {$inv->docNumber}", 'refNumber' => $inv->docNumber, 'gdiscPct' => $inv->gdiscPct, 'retPct' => $inv->retPct,
                    'retAmount' => $inv->retAmount, 'cativoPct' => $inv->cativoPct, 'cativoAmount' => $inv->cativoAmount, 'subtotal' => $inv->subtotal, 'ivaRate' => $inv->ivaRate,
                    'ivaAmount' => $inv->ivaAmount, 'grossTotal' => $inv->grossTotal ?? $inv->amount, 'amount' => $inv->amount, 'createdBy' => $user->name, 'description' => "Anula {$inv->docNumber}"]);
                foreach ($lines as $i => $l) $draft->lines()->create(['pos' => $i, 'code' => $l->code, 'description' => $l->description, 'qty' => $l->qty, 'price' => $l->price,
                    'discPct' => $l->discPct, 'ivaRate' => $l->ivaRate, 'net' => $l->net, 'tax' => $l->tax, 'itemId' => $l->itemId]);
                $created = self::emitTx($draft->id, $user);
            }
            $e = JournalEntry::where('sourceType', 'invoice')->where('sourceId', $inv->id)->whereNull('reversedOfId')->where('reversed', false)->first();
            if ($e) Accounting::reverseEntry($e->id, $user->name);
            $inv->update(['cancelledAt' => now()]);
            return $created;
        });
    }
}
