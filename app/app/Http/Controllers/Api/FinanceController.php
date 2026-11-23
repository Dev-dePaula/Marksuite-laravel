<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\{Account, Contact, Expense, Invoice, Item, JournalEntry, JournalLine, Movement, Notification, Receipt, Supplier};
use App\Services\{Accounting, Billing, Fiscal, Notify, Render, Rules};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Finanças: facturas simples, movimentos, recibos, fornecedores, despesas e contabilidade. */
class FinanceController extends Controller
{
    private function qCompany(Request $r): ?int
    {
        $n = (int) $r->query('companyId');
        return $n > 0 ? $n : null;
    }

    // ---------- facturas (vista financeira) ----------
    public function invoices(Request $r)
    {
        $q = Invoice::where('draft', false)->whereNull('cancelledAt')->whereIn('docType', Fiscal::FIN_TYPES)->with('company:id,name')->orderBy('dueDate');
        if ($r->query('open') === '1') $q->whereNull('paidAt');
        return $q->get()->map(fn ($i) => Rules::invoiceView($i));
    }

    public function storeInvoice(Request $r)
    {
        $d = $r->validate(['kind' => ['sometimes', Rule::in(['RECEBER', 'PAGAR'])], 'counterparty' => 'required|string|max:120', 'docNumber' => 'nullable|string|max:40',
            'companyId' => ['required', 'integer', $this->ex('companies')], 'dueDate' => 'required|date', 'issueDate' => 'nullable|date', 'clientNif' => 'nullable|string|max:50',
            'description' => 'nullable|string|max:2000', 'subtotal' => 'nullable|numeric|gt:0', 'amount' => 'nullable|numeric|gt:0', 'ivaRate' => 'nullable|numeric|min:0|max:100']);
        if (!isset($d['subtotal']) && !isset($d['amount'])) abort(400, 'subtotal: Indique o subtotal.');
        return response()->json(Billing::createInvoice($d, $r->user()), 201);
    }

    public function payLegacy(Request $r, int $id) { return Billing::payInvoice($id, ['paymentMethod' => 'transferencia'], $r->user()); }

    public function notifyLegacy(int $id)
    {
        $i = Invoice::findOrFail($id);
        $i->update(['lastNotifiedAt' => now()]);
        return $i;
    }

    public function receipt(Request $r, int $id)
    {
        $d = $r->validate(['amount' => 'nullable|numeric|gt:0', 'paymentDate' => 'nullable|date', 'paymentMethod' => ['sometimes', Rule::in(Billing::METHODS)], 'chargeLate' => 'sometimes|boolean']);
        return response()->json(Billing::payInvoice($id, $d, $r->user()), 201);
    }

    public function invoiceReceipts(int $id) { return Receipt::where('invoiceId', $id)->orderBy('id')->get(); }

    public function receipts()
    {
        return Receipt::with('invoice:id,docNumber,counterparty,kind')->orderByDesc('id')->limit(200)->get();
    }

    public function cancel(Request $r, int $id) { return Billing::cancelDocument($id, $r->user()) ?: ['ok' => true]; }

    // ---------- movimentos ----------
    public function movements()
    {
        return Movement::with('company:id,name')->orderByDesc('date')->orderByDesc('id')->limit(200)->get();
    }

    public function storeMovement(Request $r)
    {
        $d = $r->validate(['date' => 'required|date', 'description' => 'required|string|max:200', 'companyId' => ['nullable', 'integer', $this->ex('companies')],
            'category' => ['required', Rule::in(['Recebimentos', 'Pessoal', 'Fornecedores', 'Estrutura', 'Investimento'])], 'amount' => 'required|numeric']);
        if ((float) $d['amount'] === 0.0) abort(400, 'amount: O valor não pode ser zero.');
        return response()->json(Movement::create($d), 201);
    }

    // ---------- fornecedores ----------
    private function supRules(bool $p): array
    {
        $req = $p ? 'sometimes' : 'required';
        return ['companyId' => [$req, 'integer', $this->ex('companies')], 'name' => "$req|string|max:255", 'nif' => 'nullable|string|max:50', 'email' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:50', 'category' => 'nullable|string|max:100', 'address' => 'nullable|string|max:2000', 'active' => 'sometimes|boolean'];
    }

    public function suppliers(Request $r) { return Supplier::when($this->qCompany($r), fn ($q, $c) => $q->where('companyId', $c))->orderBy('name')->get(); }
    public function storeSupplier(Request $r) { return response()->json(Supplier::create($r->validate($this->supRules(false))), 201); }

    public function updateSupplier(Request $r, int $id)
    {
        $s = Supplier::findOrFail($id);
        $s->update($r->validate($this->supRules(true)));
        return $s;
    }

    public function destroySupplier(int $id)
    {
        $s = Supplier::findOrFail($id);
        if (Expense::where('supplierId', $id)->exists()) abort(409, 'O fornecedor tem despesas associadas; desactive-o em vez de apagar.');
        $s->delete();
        return response()->noContent();
    }

    // ---------- despesas (aprovação hierárquica) ----------
    public function expenses(Request $r)
    {
        $st = in_array($r->query('status'), ['PENDENTE', 'APROVADA', 'REJEITADA', 'PAGA']) ? $r->query('status') : null;
        return Expense::with(['company:id,name', 'supplier:id,name'])->when($st, fn ($q) => $q->where('status', $st))
            ->when($this->qCompany($r), fn ($q, $c) => $q->where('companyId', $c))->orderByDesc('id')->limit(300)->get();
    }

    public function storeExpense(Request $r)
    {
        $d = $r->validate(['companyId' => ['required', 'integer', $this->ex('companies')], 'supplierId' => ['nullable', 'integer', $this->ex('suppliers')],
            'description' => 'required|string|max:255', 'category' => 'nullable|string|max:100', 'dueDate' => 'nullable|date', 'subtotal' => 'required|numeric|gt:0', 'ivaRate' => 'nullable|numeric|min:0|max:100']);
        $t = Billing::computeTotals(['subtotal' => $d['subtotal'], 'ivaRate' => $d['ivaRate'] ?? null]);
        return response()->json(Expense::create(['companyId' => $d['companyId'], 'supplierId' => $d['supplierId'] ?? null, 'description' => $d['description'],
            'category' => $d['category'] ?? null, 'dueDate' => $d['dueDate'] ?? null, 'requestedBy' => $r->user()->name] + $t), 201);
    }

    public function approveExpense(Request $r, int $id)
    {
        $note = $r->validate(['note' => 'nullable|string|max:2000'])['note'] ?? null;
        return DB::transaction(function () use ($id, $note, $r) {
            $e = Expense::where('status', 'PENDENTE')->find($id) ?? abort(404, 'Despesa não encontrada ou já decidida.');
            $A = Accounting::ACC;
            Accounting::postEntry(['companyId' => $e->companyId, 'date' => Rules::today(), 'description' => "Despesa aprovada #{$e->id} — {$e->description}",
                'lines' => [['code' => $A['DESPESA'], 'debit' => $e->subtotal], ['code' => $A['IVA_DEDUTIVEL'], 'debit' => $e->ivaAmount], ['code' => $A['FORNECEDORES'], 'credit' => $e->amount]],
                'source' => 'billing', 'sourceType' => 'expense', 'sourceId' => $e->id, 'user' => $r->user()->name]);
            $e->update(['status' => 'APROVADA', 'approvalNote' => $note, 'decidedAt' => now()]);
            return $e;
        });
    }

    public function rejectExpense(Request $r, int $id)
    {
        $note = $r->validate(['note' => 'required|string|max:2000'])['note'];
        $e = Expense::where('status', 'PENDENTE')->find($id) ?? abort(404, 'Despesa não encontrada ou já decidida.');
        $e->update(['status' => 'REJEITADA', 'approvalNote' => $note, 'decidedAt' => now()]);
        return $e;
    }

    public function payExpense(Request $r, int $id)
    {
        $m = $r->validate(['paymentMethod' => ['sometimes', Rule::in(Billing::METHODS)]])['paymentMethod'] ?? 'transferencia';
        return DB::transaction(function () use ($id, $m, $r) {
            $e = Expense::where('status', 'APROVADA')->find($id) ?? abort(409, 'Só despesas aprovadas podem ser pagas.');
            $A = Accounting::ACC;
            Accounting::postEntry(['companyId' => $e->companyId, 'date' => Rules::today(), 'description' => "Pagamento da despesa #{$e->id} — {$e->description}",
                'lines' => [['code' => $A['FORNECEDORES'], 'debit' => $e->amount], ['code' => Billing::bankCode($m), 'credit' => $e->amount]],
                'source' => 'billing', 'sourceType' => 'expense-payment', 'sourceId' => $e->id, 'user' => $r->user()->name]);
            Movement::create(['date' => Rules::today(), 'description' => "Pagamento — {$e->description}", 'companyId' => $e->companyId, 'category' => 'Fornecedores', 'amount' => -$e->amount]);
            $e->update(['status' => 'PAGA', 'paidAt' => now()]);
            return $e;
        });
    }

    public function destroyExpense(int $id)
    {
        $e = Expense::findOrFail($id);
        if (!in_array($e->status, ['PENDENTE', 'REJEITADA'])) abort(409, 'Só despesas pendentes ou rejeitadas podem ser apagadas.');
        $e->delete();
        return response()->noContent();
    }

    // ---------- contabilidade ----------
    public function accounts(Request $r)
    {
        $cid = $this->qCompany($r) ?? abort(400, 'Indique companyId.');
        Accounting::ensureChart($cid);
        return Account::where('companyId', $cid)->orderBy('code')->get();
    }

    public function storeAccount(Request $r)
    {
        $d = $r->validate(['companyId' => ['required', 'integer', $this->ex('companies')], 'code' => ['required', 'regex:/^\d+(\.\d+)*$/', 'max:20'], 'name' => 'required|string|max:255',
            'class' => ['required', Rule::in(['ATIVO', 'PASSIVO', 'PATRIMONIO', 'RECEITA', 'DESPESA'])], 'synthetic' => 'sometimes|boolean', 'parentId' => ['nullable', 'integer', $this->ex('accounts')]]);
        Accounting::ensureChart($d['companyId']);
        if (Account::where('companyId', $d['companyId'])->where('code', $d['code'])->exists()) abort(409, 'Já existe uma conta com este código.');
        return response()->json(Account::create($d), 201);
    }

    public function updateAccount(Request $r, int $id)
    {
        $a = Account::findOrFail($id);
        $a->update($r->validate(['name' => 'sometimes|string|max:255', 'active' => 'sometimes|boolean']));
        return $a;
    }

    public function destroyAccount(int $id)
    {
        $a = Account::findOrFail($id);
        if ($a->isSystem) abort(409, 'Contas do sistema não podem ser apagadas.');
        if (JournalLine::where('accountId', $id)->exists()) abort(409, 'A conta tem lançamentos; desactive-a.');
        $a->delete();
        return response()->noContent();
    }

    public function entries(Request $r)
    {
        return JournalEntry::with(['lines.account:id,code,name', 'company:id,name'])
            ->when($this->qCompany($r), fn ($q, $c) => $q->where('companyId', $c))->when($r->query('source'), fn ($q, $s) => $q->where('source', $s))
            ->when($r->query('from'), fn ($q, $f) => $q->where('entryDate', '>=', $f))->when($r->query('to'), fn ($q, $t) => $q->where('entryDate', '<=', $t))
            ->orderByDesc('entryDate')->orderByDesc('id')->limit(200)->get();
    }

    public function entry(int $id) { return JournalEntry::with(['lines.account:id,code,name', 'company:id,name'])->findOrFail($id); }

    public function storeEntry(Request $r)
    {
        $d = $r->validate(['companyId' => ['required', 'integer', $this->ex('companies')], 'entryDate' => 'nullable|date', 'description' => 'required|string|max:500',
            'lines' => 'required|array|min:2', 'lines.*.code' => 'required|string|max:20', 'lines.*.debit' => 'sometimes|numeric|min:0', 'lines.*.credit' => 'sometimes|numeric|min:0', 'lines.*.memo' => 'nullable|string|max:255']);
        $e = DB::transaction(fn () => Accounting::postEntry(['companyId' => $d['companyId'], 'date' => $d['entryDate'] ?? null, 'description' => $d['description'], 'lines' => $d['lines'], 'user' => $r->user()->name]));
        return response()->json($e, 201);
    }

    public function reverseEntry(Request $r, int $id) { return response()->json(DB::transaction(fn () => Accounting::reverseEntry($id, $r->user()->name)), 201); }

    private function period(Request $r): array { return ['companyId' => $this->qCompany($r), 'from' => $r->query('from'), 'to' => $r->query('to')]; }

    public function ledger(Request $r)
    {
        if (!$r->query('code')) abort(400, 'Indique code da conta.');
        return Accounting::ledger($this->period($r) + ['code' => (string) $r->query('code')]);
    }

    public function balancete(Request $r) { return Accounting::balancete($this->period($r)); }
    public function dre(Request $r) { return Accounting::dre($this->period($r)); }
    public function balanco(Request $r) { return Accounting::balanco(['companyId' => $this->qCompany($r), 'asOf' => $r->query('asOf')]); }
    public function summary(Request $r) { return Accounting::summary($this->qCompany($r)); }
    public function taxes(Request $r) { return Accounting::taxes($this->period($r)); }

    public function taxSettings(Request $r)
    {
        $d = $r->validate(['ivaRate' => 'sometimes|numeric|min:0|max:100', 'indRate' => 'sometimes|numeric|min:0|max:100']);
        foreach ($d as $k => $v) Rules::setSetting($k, $v);
        return Accounting::taxSettings();
    }
}
