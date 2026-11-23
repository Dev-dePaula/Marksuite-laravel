<?php

namespace App\Services;

use App\Models\{Account, DocCounter, JournalEntry, JournalLine, Setting};
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/** Motor de contabilidade automática (porta de accounting.js): plano de contas, partidas dobradas, relatórios e impostos. */
class Accounting
{
    public const TAX_DEFAULTS = ['ivaRate' => '14', 'indRate' => '25'];

    public const ACC = [
        'CAIXA' => '1.1.1', 'BANCOS' => '1.1.2', 'CLIENTES' => '1.1.3', 'IVA_DEDUTIVEL' => '1.1.4', 'RETENCOES' => '1.1.5',
        'FORNECEDORES' => '2.1.1', 'IVA_LIQUIDADO' => '2.1.2', 'IMP_INDUSTRIAL_PAGAR' => '2.1.3', 'ADIANTAMENTOS' => '2.1.4',
        'RECEITA' => '4.1', 'JUROS' => '4.2', 'DESPESA' => '5.1', 'IMP_INDUSTRIAL' => '5.2',
    ];

    public const CHART = [
        ['1', 'ATIVO', 'ATIVO', true, null], ['1.1', 'Ativo Circulante', 'ATIVO', true, '1'],
        ['1.1.1', 'Caixa', 'ATIVO', false, '1.1'], ['1.1.2', 'Bancos', 'ATIVO', false, '1.1'],
        ['1.1.3', 'Clientes / Contas a Receber', 'ATIVO', false, '1.1'], ['1.1.4', 'IVA Dedutível', 'ATIVO', false, '1.1'],
        ['1.1.5', 'Retenções na fonte a recuperar', 'ATIVO', false, '1.1'],
        ['2', 'PASSIVO', 'PASSIVO', true, null], ['2.1', 'Passivo Circulante', 'PASSIVO', true, '2'],
        ['2.1.1', 'Fornecedores / Contas a Pagar', 'PASSIVO', false, '2.1'], ['2.1.2', 'IVA Liquidado (a Pagar)', 'PASSIVO', false, '2.1'],
        ['2.1.3', 'Imposto Industrial a Pagar', 'PASSIVO', false, '2.1'], ['2.1.4', 'Adiantamentos de Clientes', 'PASSIVO', false, '2.1'],
        ['3', 'PATRIMÓNIO LÍQUIDO', 'PATRIMONIO', true, null], ['3.1', 'Capital Social', 'PATRIMONIO', false, '3'],
        ['3.2', 'Resultados Acumulados', 'PATRIMONIO', false, '3'],
        ['4', 'RECEITAS', 'RECEITA', true, null], ['4.1', 'Receita de Vendas/Serviços', 'RECEITA', false, '4'],
        ['4.2', 'Juros de Mora e Multas', 'RECEITA', false, '4'],
        ['5', 'DESPESAS', 'DESPESA', true, null], ['5.1', 'Despesas Operacionais', 'DESPESA', false, '5'],
        ['5.2', 'Imposto Industrial', 'DESPESA', false, '5'],
    ];

    public static function r2($n): float { return round((float) $n + 1e-9, 2); }

    public static function taxSettings(): array
    {
        $s = self::TAX_DEFAULTS;
        foreach (Setting::whereIn('key', array_keys($s))->get() as $r) $s[$r->key] = $r->value;
        return ['ivaRate' => (float) $s['ivaRate'], 'indRate' => (float) $s['indRate']];
    }

    /** Cria (idempotente) o plano de contas por omissão da empresa. */
    public static function ensureChart(int $companyId): void
    {
        $codes = array_column(self::CHART, 0);
        if (Account::where('companyId', $companyId)->whereIn('code', $codes)->count() >= count($codes)) return;
        $ids = [];
        foreach (self::CHART as [$code, $name, $cls, $syn, $parent]) {
            $a = Account::firstOrCreate(['companyId' => $companyId, 'code' => $code],
                ['name' => $name, 'class' => $cls, 'synthetic' => $syn, 'parentId' => $parent ? ($ids[$parent] ?? null) : null, 'isSystem' => true]);
            $ids[$code] = $a->id;
        }
    }

    /** Numeração sequencial imutável: FT 2026/0001, RC 2026/0001, LC-000001. */
    public static function nextDocNumber(string $type, $date = null, $serie = null): string
    {
        $date = $date ? Carbon::parse($date) : now();
        $year = $type === 'LC' ? '' : (string) ($serie ?: $date->year);
        $seq = self::bump($type . $year);
        return $type === 'LC' ? 'LC-' . str_pad($seq, 6, '0', STR_PAD_LEFT) : "$type $year/" . str_pad($seq, 4, '0', STR_PAD_LEFT);
    }

    /** Incremento atómico de um contador (por tenant); devolve o número atribuído. */
    public static function bump(string $key): int
    {
        DocCounter::firstOrCreate(['key' => $key]);
        DocCounter::where('key', $key)->increment('nextSeq');
        return DocCounter::where('key', $key)->value('nextSeq') - 1;
    }

    /** Lança um registo: rejeita desequilibrados / linhas com débito e crédito. Deve correr numa transacção. */
    public static function postEntry(array $o): JournalEntry
    {
        $clean = array_values(array_filter(array_map(fn ($l) => [
            'code' => $l['code'] ?? null, 'accountId' => $l['accountId'] ?? null, 'memo' => $l['memo'] ?? null,
            'debit' => self::r2($l['debit'] ?? 0), 'credit' => self::r2($l['credit'] ?? 0),
        ], $o['lines']), fn ($l) => $l['debit'] || $l['credit']));
        if (count($clean) < 2) abort(422, 'Um lançamento precisa de pelo menos 2 linhas.');
        $td = 0; $tc = 0;
        foreach ($clean as $l) {
            if ($l['debit'] < 0 || $l['credit'] < 0) abort(422, 'Débito/crédito não podem ser negativos.');
            if ($l['debit'] > 0 && $l['credit'] > 0) abort(422, 'Cada linha deve ter débito OU crédito.');
            $td += $l['debit']; $tc += $l['credit'];
        }
        if (self::r2($td) !== self::r2($tc)) abort(422, 'Lançamento desequilibrado: débito ' . self::r2($td) . ' ≠ crédito ' . self::r2($tc) . '.');

        self::ensureChart($o['companyId']);
        $accounts = Account::where('companyId', $o['companyId'])->get();
        $lineData = array_map(function ($l) use ($accounts) {
            $a = $l['accountId'] ? $accounts->firstWhere('id', $l['accountId']) : $accounts->firstWhere('code', $l['code']);
            if (!$a) abort(422, 'Conta inexistente: ' . ($l['code'] ?? $l['accountId']) . '.');
            if (!$a->active) abort(422, "Conta inactiva: {$a->code}.");
            if ($a->synthetic) abort(422, "A conta {$a->code} é sintética; lance numa conta analítica.");
            return ['accountId' => $a->id, 'debit' => $l['debit'], 'credit' => $l['credit'], 'memo' => $l['memo']];
        }, $clean);

        $entry = JournalEntry::create([
            'docNumber' => self::nextDocNumber('LC'), 'companyId' => $o['companyId'], 'entryDate' => Carbon::parse($o['date'] ?? now())->startOfDay(),
            'description' => $o['description'], 'source' => $o['source'] ?? 'manual', 'sourceType' => $o['sourceType'] ?? null,
            'sourceId' => $o['sourceId'] ?? null, 'createdBy' => $o['user'],
        ]);
        foreach ($lineData as $ld) $entry->lines()->create($ld);
        return $entry->load('lines.account');
    }

    public static function reverseEntry(int $id, string $user): JournalEntry
    {
        $e = JournalEntry::with('lines')->findOrFail($id);
        if ($e->reversed || $e->reversedOfId) abort(409, 'Lançamento já estornado ou é um estorno.');
        $rev = JournalEntry::create([
            'docNumber' => self::nextDocNumber('LC'), 'companyId' => $e->companyId, 'entryDate' => Carbon::today('UTC'),
            'description' => "Estorno de {$e->docNumber} — {$e->description}", 'source' => $e->source, 'sourceType' => $e->sourceType,
            'sourceId' => $e->sourceId, 'reversedOfId' => $e->id, 'createdBy' => $user,
        ]);
        foreach ($e->lines as $l) $rev->lines()->create(['accountId' => $l->accountId, 'debit' => $l->credit, 'credit' => $l->debit, 'memo' => $l->memo]);
        $e->update(['reversed' => true]);
        return $rev->load('lines.account');
    }

    // ---------------------------------------------------------------- relatórios
    private static function lines(?int $companyId, $from = null, $to = null)
    {
        return JournalLine::whereHas('entry', function ($q) use ($companyId, $from, $to) {
            if ($companyId) $q->where('companyId', $companyId);
            if ($from) $q->where('entryDate', '>=', Carbon::parse($from)->startOfDay());
            if ($to) $q->where('entryDate', '<=', Carbon::parse($to)->startOfDay());
        })->with('account')->get();
    }

    private static function aggregate($lines): array
    {
        $m = [];
        foreach ($lines as $l) {
            $a = $l->account;
            $m[$a->code] ??= ['code' => $a->code, 'name' => $a->name, 'class' => $a->class, 'debit' => 0.0, 'credit' => 0.0];
            $m[$a->code]['debit'] += $l->debit; $m[$a->code]['credit'] += $l->credit;
        }
        uksort($m, fn ($a, $b) => strnatcmp($a, $b));
        return array_values($m);
    }

    public static function balancete(array $q): array
    {
        $rows = array_map(fn ($r) => $r + ['balance' => 0], self::aggregate(self::lines($q['companyId'] ?? null, $q['from'] ?? null, $q['to'] ?? null)));
        foreach ($rows as &$r) { $r['debit'] = self::r2($r['debit']); $r['credit'] = self::r2($r['credit']); $r['balance'] = self::r2($r['debit'] - $r['credit']); }
        $td = self::r2(array_sum(array_column($rows, 'debit'))); $tc = self::r2(array_sum(array_column($rows, 'credit')));
        return ['accounts' => $rows, 'totalDebit' => $td, 'totalCredit' => $tc, 'balanced' => $td === $tc];
    }

    public static function dre(array $q): array
    {
        $rows = array_values(array_filter(self::aggregate(self::lines($q['companyId'] ?? null, $q['from'] ?? null, $q['to'] ?? null)), fn ($r) => in_array($r['class'], ['RECEITA', 'DESPESA'])));
        foreach ($rows as &$r) $r['total'] = self::r2($r['class'] === 'RECEITA' ? $r['credit'] - $r['debit'] : $r['debit'] - $r['credit']);
        unset($r);
        $rec = array_values(array_filter($rows, fn ($r) => $r['class'] === 'RECEITA'));
        $des = array_values(array_filter($rows, fn ($r) => $r['class'] === 'DESPESA'));
        $tr = self::r2(array_sum(array_column($rec, 'total'))); $td = self::r2(array_sum(array_column($des, 'total')));
        return ['receitas' => $rec, 'despesas' => $des, 'totalReceitas' => $tr, 'totalDespesas' => $td, 'resultadoLiquido' => self::r2($tr - $td)];
    }

    public static function balanco(array $q): array
    {
        $asOf = $q['asOf'] ?? null;
        $all = self::aggregate(self::lines($q['companyId'] ?? null, null, $asOf ?: now()));
        $sign = fn ($r) => self::r2(in_array($r['class'], ['ATIVO', 'DESPESA']) ? $r['debit'] - $r['credit'] : $r['credit'] - $r['debit']);
        $pick = fn ($c) => array_values(array_map(fn ($r) => $r + ['total' => $sign($r)], array_filter($all, fn ($r) => $r['class'] === $c)));
        $ativo = $pick('ATIVO'); $passivo = $pick('PASSIVO'); $pat = $pick('PATRIMONIO');
        $res = 0;
        foreach ($all as $r) {
            if ($r['class'] === 'RECEITA') $res += $r['credit'] - $r['debit'];
            if ($r['class'] === 'DESPESA') $res -= $r['debit'] - $r['credit'];
        }
        $res = self::r2($res);
        if ($res) $pat[] = ['code' => '3.9', 'name' => 'Resultado do Período (não apurado)', 'class' => 'PATRIMONIO', 'total' => $res];
        $sum = fn ($a) => self::r2(array_sum(array_column($a, 'total')));
        $ta = $sum($ativo); $tp = $sum($passivo); $tpl = $sum($pat);
        return ['asOf' => $asOf ?: now()->toDateString(), 'ativo' => $ativo, 'passivo' => $passivo, 'patrimonio' => $pat,
            'totalAtivo' => $ta, 'totalPassivo' => $tp, 'totalPatrimonio' => $tpl, 'balanceado' => $ta === self::r2($tp + $tpl)];
    }

    public static function ledger(array $q): array
    {
        $code = $q['code'];
        $lines = self::lines($q['companyId'] ?? null, $q['from'] ?? null, $q['to'] ?? null)->filter(fn ($l) => $l->account->code === $code)->values();
        $entries = JournalEntry::whereIn('id', $lines->pluck('entryId')->unique())->get()->keyBy('id');
        $lines = $lines->sort(fn ($a, $b) => [$entries[$a->entryId]->entryDate, $a->id] <=> [$entries[$b->entryId]->entryDate, $b->id])->values();
        $debitNormal = $lines->isEmpty() || in_array($lines[0]->account->class, ['ATIVO', 'DESPESA']);
        $bal = 0; $rows = [];
        foreach ($lines as $l) {
            $bal += $debitNormal ? $l->debit - $l->credit : $l->credit - $l->debit;
            $e = $entries[$l->entryId];
            $rows[] = ['entryId' => $e->id, 'docNumber' => $e->docNumber, 'date' => $e->entryDate, 'description' => $e->description,
                'memo' => $l->memo, 'debit' => $l->debit, 'credit' => $l->credit, 'balance' => self::r2($bal)];
        }
        return ['code' => $code, 'rows' => $rows, 'balance' => self::r2($bal)];
    }

    public static function taxes(array $q): array
    {
        $cfg = self::taxSettings();
        $iL = 0; $iD = 0; $iInd = 0; $rec = 0; $des = 0;
        foreach (self::lines($q['companyId'] ?? null, $q['from'] ?? null, $q['to'] ?? null) as $l) {
            $c = $l->account->code;
            if ($c === self::ACC['IVA_LIQUIDADO']) $iL += $l->credit - $l->debit;
            if ($c === self::ACC['IVA_DEDUTIVEL']) $iD += $l->debit - $l->credit;
            if ($c === self::ACC['IMP_INDUSTRIAL']) $iInd += $l->debit - $l->credit;
            if ($l->account->class === 'RECEITA') $rec += $l->credit - $l->debit;
            if ($l->account->class === 'DESPESA' && $c !== self::ACC['IMP_INDUSTRIAL']) $des += $l->debit - $l->credit;
        }
        $res = self::r2($rec - $des);
        $est = $res > 0 ? self::r2($res * $cfg['indRate'] / 100) : 0;
        return [
            'rates' => $cfg,
            'iva' => ['liquidado' => self::r2($iL), 'dedutivel' => self::r2($iD), 'apagar' => self::r2($iL - $iD)],
            'impostoIndustrial' => ['resultadoAntesImposto' => $res, 'taxa' => $cfg['indRate'], 'estimado' => $est, 'jaLancado' => self::r2($iInd), 'emFalta' => self::r2($est - $iInd)],
            'aviso' => 'Valores de gestão calculados a partir da contabilidade; a declaração fiscal oficial deve ser validada por um contabilista certificado.',
        ];
    }

    public static function summary(?int $companyId): array
    {
        $from = Carbon::today('UTC')->startOfMonth();
        $all = self::lines($companyId); $month = self::lines($companyId, $from);
        $bal = fn ($codes, $debitNormal) => self::r2($all->filter(fn ($l) => in_array($l->account->code, $codes))
            ->sum(fn ($l) => $debitNormal ? $l->debit - $l->credit : $l->credit - $l->debit));
        $res = $month->sum(fn ($l) => $l->account->class === 'RECEITA' ? $l->credit - $l->debit : ($l->account->class === 'DESPESA' ? -($l->debit - $l->credit) : 0));
        $count = JournalEntry::when($companyId, fn ($q) => $q->where('companyId', $companyId))->where('entryDate', '>=', $from)->count();
        return [
            'saldoCaixaBancos' => $bal([self::ACC['CAIXA'], self::ACC['BANCOS']], true), 'totalAReceber' => $bal([self::ACC['CLIENTES']], true),
            'totalAPagar' => $bal([self::ACC['FORNECEDORES']], false), 'lancamentosMes' => $count, 'resultadoMes' => self::r2($res),
        ];
    }
}
