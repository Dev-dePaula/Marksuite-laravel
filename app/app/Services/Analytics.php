<?php

namespace App\Services;

use App\Models\{AiInsight, Company, Decision, Employee, HealthSnapshot, Invoice, KpiSnapshot, Movement, Opportunity, Process, Project};
use App\Support\Tenancy;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Central de Análise: histórico diário de KPIs, comparação entre períodos, alertas e insights de IA (porta de analytics.js).
 * Todas as datas são tratadas como "dia" inteiro (dias desde 1970, UTC).
 */
class Analytics
{
    /** good: 'up' (subir é bom), 'down' (descer é bom), null (neutro). group: só existe para o Grupo (scope 0). */
    public const KPIS = [
        'cash' => ['label' => 'Caixa', 'unit' => 'AOA', 'good' => 'up', 'group' => true],
        'revenue30' => ['label' => 'Receita (30 dias)', 'unit' => 'AOA', 'good' => 'up'],
        'cost30' => ['label' => 'Custos (30 dias)', 'unit' => 'AOA', 'good' => 'down'],
        'pipeline' => ['label' => 'Pipeline aberto', 'unit' => 'AOA', 'good' => 'up'],
        'receivableOpen' => ['label' => 'A receber (em aberto)', 'unit' => 'AOA', 'good' => null],
        'payableOpen' => ['label' => 'A pagar (em aberto)', 'unit' => 'AOA', 'good' => null],
        'overdueTotal' => ['label' => 'Cobrança vencida', 'unit' => 'AOA', 'good' => 'down'],
        'overdueCount' => ['label' => 'Documentos vencidos', 'unit' => 'n', 'good' => 'down'],
        'health' => ['label' => 'Saúde', 'unit' => 'pts', 'good' => 'up'],
        'projectsRisk' => ['label' => 'Projectos em risco', 'unit' => 'n', 'good' => 'down'],
        'staleOpps' => ['label' => 'Propostas paradas', 'unit' => 'n', 'good' => 'down'],
        'overloaded' => ['label' => 'Colaboradores sobrecarregados', 'unit' => 'n', 'good' => 'down'],
        'pendingDecisions' => ['label' => 'Decisões pendentes', 'unit' => 'n', 'good' => 'down', 'group' => true],
        'opsAvg' => ['label' => 'Execução de processos', 'unit' => '%', 'good' => 'up', 'group' => true],
    ];

    public static function keysFor(int $scope): array
    {
        return array_values(array_filter(array_keys(self::KPIS), fn ($k) => $scope === 0 || empty(self::KPIS[$k]['group'])));
    }

    public static function dayOf($d): int { return (int) floor(Carbon::parse($d)->startOfDay()->getTimestamp() / 86400) + 0; }
    public static function today(): int { return (int) floor(Carbon::today('UTC')->getTimestamp() / 86400); }
    public static function iso(int $day): string { return Carbon::createFromTimestampUTC($day * 86400)->toDateString(); }

    private static function utcDay($d): int
    {
        $c = Carbon::parse($d);
        return (int) floor(Carbon::create($c->year, $c->month, $c->day, 0, 0, 0, 'UTC')->getTimestamp() / 86400);
    }

    private static function fmt(float $v, string $unit): string
    {
        return $unit === 'AOA' ? number_format(round($v), 0, ',', ' ') . ' AOA' : ($unit === '%' ? round($v) . '%' : (string) (round($v * 10) / 10));
    }

    // ---------------------------------------------------------------- dados de origem
    private static function load(): array
    {
        $cfg = Rules::settings();
        $M = Movement::get(['date', 'companyId', 'category', 'amount'])->map(fn ($m) => ['t' => self::utcDay($m->date), 'c' => $m->companyId, 'amt' => $m->amount,
            'rev' => ($m->category === 'Recebimentos' && $m->amount > 0) ? $m->amount : 0, 'cost' => $m->amount < 0 ? -$m->amount : 0])->all();
        $I = Invoice::where('draft', false)->whereIn('docType', Fiscal::FIN_TYPES)->get(['kind', 'companyId', 'dueDate', 'amount', 'paidAt', 'issueDate', 'createdAt', 'cancelledAt'])
            ->map(fn ($i) => ['kind' => $i->kind, 'c' => $i->companyId, 'due' => self::utcDay($i->dueDate), 'amt' => $i->amount, 'paid' => $i->paidAt ? self::utcDay($i->paidAt) : null,
                'issued' => self::utcDay($i->issueDate ?? $i->createdAt), 'cancelled' => $i->cancelledAt ? self::utcDay($i->cancelledAt) : null])->all();
        $O = Opportunity::get()->map(fn ($o) => ['companyId' => $o->companyId, 'v' => $o->value, 'stage' => $o->stage, 'lastContact' => $o->lastContact, 'outcome' => $o->outcome,
            'created' => self::utcDay($o->createdAt ?? now()), 'closed' => $o->closedAt ? self::utcDay($o->closedAt) : null])->all();
        $min = fn ($a, $f) => $a ? min(array_map($f, $a)) : PHP_INT_MAX;
        return ['cfg' => $cfg, 'companies' => Company::orderBy('id')->get(), 'M' => $M, 'I' => $I, 'O' => $O, 'projs' => Project::get(), 'emps' => Employee::get(['companyId', 'capacity']),
            'procs' => Process::get(['execution']), 'pendingDecisions' => Decision::where('status', 'PENDENTE')->count(), 'health' => HealthSnapshot::get(),
            'minMov' => $min($M, fn ($x) => $x['t']), 'minInv' => $min($I, fn ($x) => $x['issued']), 'minOpp' => $min($O, fn ($x) => $x['created'])];
    }

    /** KPIs reconstruíveis para qualquer dia (finanças e pipeline). Cada família só existe a partir do seu registo mais antigo. */
    private static function backfillAt(array $D, int $t): array
    {
        $out = []; $scopes = array_merge([0], $D['companies']->pluck('id')->all());
        $init = function (array $keys) use (&$out, $scopes) { foreach ($scopes as $s) foreach ($keys as $k) $out[$s][$k] = 0; };
        $add = function ($s, $k, $v) use (&$out) { if (isset($out[$s])) $out[$s][$k] += $v; };
        if ($D['M'] && $t >= $D['minMov']) {
            $init(['revenue30', 'cost30']); $cash = $D['cfg']['openingCash'];
            foreach ($D['M'] as $m) {
                if ($m['t'] > $t) continue;
                $cash += $m['amt'];
                if ($m['t'] > $t - 30) foreach ($m['c'] ? [0, $m['c']] : [0] as $s) { $add($s, 'revenue30', $m['rev']); $add($s, 'cost30', $m['cost']); }
            }
            $out[0]['cash'] = $cash;
        }
        if ($D['I'] && $t >= $D['minInv']) {
            $init(['receivableOpen', 'payableOpen', 'overdueTotal', 'overdueCount']);
            foreach ($D['I'] as $i) {
                if ($i['issued'] > $t || ($i['cancelled'] !== null && $i['cancelled'] <= $t)) continue;
                if ($i['paid'] !== null && $i['paid'] <= $t) continue;
                foreach ([0, $i['c']] as $s) {
                    if ($i['kind'] === 'RECEBER') { $add($s, 'receivableOpen', $i['amt']); if ($i['due'] < $t) { $add($s, 'overdueTotal', $i['amt']); $add($s, 'overdueCount', 1); } }
                    else $add($s, 'payableOpen', $i['amt']);
                }
            }
        }
        if ($D['O'] && $t >= $D['minOpp']) {
            $init(['pipeline']);
            foreach ($D['O'] as $o) {
                if ($o['created'] > $t || ($o['closed'] !== null && $o['closed'] <= $t)) continue;
                if ($o['closed'] === null && $o['outcome'] !== 'ABERTA') continue;
                $add(0, 'pipeline', $o['v']); $add($o['companyId'], 'pipeline', $o['v']);
            }
        }
        return $out;
    }

    // ---------------------------------------------------------------- captura
    private static function store(array $rows, int $day): void
    {
        $ts = Carbon::createFromTimestampUTC($day * 86400)->toDateString();
        foreach (array_chunk($rows, 100) as $chunk) {
            foreach ($chunk as $r) KpiSnapshot::updateOrCreate(['day' => $ts, 'companyId' => $r[0], 'key' => $r[1]], ['value' => $r[2]]);
        }
    }

    public static function captureToday(): void
    {
        $D = self::load(); $t = self::today(); $rows = [];
        foreach (self::backfillAt($D, $t) as $s => $o) foreach ($o as $k => $v) $rows[] = [$s, $k, (float) $v];
        $counts = function ($list, $pred, $key) use (&$rows, $D) {
            $rows[] = [0, $key, (float) count(array_filter($list, $pred))];
            foreach ($D['companies'] as $c) $rows[] = [$c->id, $key, (float) count(array_filter($list, fn ($x) => ($x['companyId'] ?? $x->companyId ?? null) == $c->id && $pred($x)))];
        };
        $hs = $D['companies']->map(fn ($c) => Rules::companyHealth($c))->all();
        foreach ($D['companies'] as $i => $c) $rows[] = [$c->id, 'health', (float) $hs[$i]];
        if ($hs) $rows[] = [0, 'health', (float) round(array_sum($hs) / count($hs))];
        $projects = $D['projs']->map(fn ($p) => Rules::withProjectMeta($p, $D['cfg']))->all();
        $counts($projects, fn ($p) => in_array($p->status, ['Em risco', 'Atrasado']), 'projectsRisk');
        $counts($D['O'], fn ($o) => $o['outcome'] === 'ABERTA' && in_array($o['stage'], ['PROPOSTA', 'NEGOCIACAO']) && -Rules::daysTo($o['lastContact']) >= $D['cfg']['prop'], 'staleOpps');
        $counts($D['emps']->all(), fn ($e) => $e->capacity > 100, 'overloaded');
        $rows[] = [0, 'pendingDecisions', (float) $D['pendingDecisions']];
        if ($D['procs']->count()) $rows[] = [0, 'opsAvg', (float) $D['procs']->avg('execution')];
        self::store($rows, $t);
    }

    /** Reconstrói o passado a partir dos registos (só corre com a tabela vazia). */
    public static function backfill(int $days = 90): int
    {
        $D = self::load(); $t0 = self::today(); $n = 0;
        for ($i = $days; $i >= 1; $i--) {
            $rows = [];
            foreach (self::backfillAt($D, $t0 - $i) as $s => $o) foreach ($o as $k => $v) $rows[] = [$s, $k, (float) $v];
            self::store($rows, $t0 - $i); $n += count($rows);
        }
        $byMonth = [];
        foreach ($D['health'] as $h) { $t = self::utcDay($h->month); if ($t >= $t0 - $days) $byMonth[$t][] = $h; }
        foreach ($byMonth as $t => $list) {
            $rows = array_map(fn ($h) => [$h->companyId, 'health', (float) $h->score], $list);
            $rows[] = [0, 'health', (float) round(array_sum(array_map(fn ($h) => $h->score, $list)) / count($list))];
            self::store($rows, $t);
        }
        return $n;
    }

    /** Garante dados recentes: backfill na primeira vez e captura a cada 10 minutos. */
    public static function freshen(): void
    {
        if (!KpiSnapshot::exists()) { try { self::backfill(); } catch (\Throwable $e) { report($e); } }
        $last = KpiSnapshot::where('day', Carbon::today('UTC')->toDateString())->max('updatedAt');
        if (!$last || Carbon::parse($last)->diffInMinutes(now()) >= 10) self::captureToday();
    }

    // ---------------------------------------------------------------- leitura / comparação
    public static function loadSeries(int $days): array
    {
        $from = Carbon::createFromTimestampUTC((self::today() - ($days + 45)) * 86400)->toDateString();
        $map = [];
        foreach (KpiSnapshot::where('day', '>=', $from)->orderBy('day')->get() as $r) $map["{$r->companyId}|{$r->key}"][] = ['t' => self::utcDay($r->day), 'v' => $r->value];
        return $map;
    }

    public static function view(?array $arr, string $key, int $days): ?array
    {
        if (!$arr) return null;
        $meta = self::KPIS[$key]; $target = self::today() - $days; $current = end($arr)['v']; $previous = null;
        foreach (array_reverse($arr) as $r) if ($r['t'] <= $target) { $previous = $r['v']; break; }
        $delta = $deltaPct = $status = null;
        if ($previous !== null) {
            $delta = $current - $previous; $deltaPct = $previous != 0 ? $delta / abs($previous) * 100 : null;
            $status = $delta == 0 ? 'igual' : ($meta['good'] ? ((($delta > 0) === ($meta['good'] === 'up')) ? 'melhor' : 'pior') : 'neutro');
        }
        return ['key' => $key, 'label' => $meta['label'], 'unit' => $meta['unit'], 'good' => $meta['good'], 'current' => $current, 'previous' => $previous, 'delta' => $delta,
            'deltaPct' => $deltaPct, 'status' => $status, 'series' => array_values(array_map(fn ($r) => ['day' => self::iso($r['t']), 'value' => $r['v']], array_filter($arr, fn ($r) => $r['t'] >= $target)))];
    }

    public static function scopes(): array
    {
        return array_merge([['id' => 0, 'name' => 'Grupo']], Company::orderBy('id')->get(['id', 'name'])->map(fn ($c) => ['id' => $c->id, 'name' => $c->name])->all());
    }

    public static function alerts(int $days, array $series): array
    {
        $thr = (float) (\App\Models\Setting::where('key', 'analyticsDelta')->value('value') ?: 0); $thr = $thr > 0 ? $thr : 20; $out = [];
        foreach (self::scopes() as $s) {
            foreach (self::keysFor($s['id']) as $k) {
                $v = self::view($series["{$s['id']}|$k"] ?? null, $k, $days);
                if (!$v || $v['previous'] === null || $v['status'] !== 'pior') continue;
                $abs = abs($v['delta']); $unit = $v['unit'];
                if ($unit === 'pts' || $unit === '%') { if ($abs < 5) continue; $level = $abs >= 10 ? 'Alta' : 'Média'; }
                elseif ($unit === 'n') {
                    if ($abs < 1) continue;
                    $p = $v['previous'] == 0 ? INF : $abs / abs($v['previous']) * 100;
                    if ($p < $thr) continue;
                    $level = $p !== INF && $p >= $thr * 2 ? 'Alta' : 'Média';
                } else {
                    if ($v['previous'] == 0) $level = 'Média';
                    else { $p = $abs / abs($v['previous']) * 100; if ($p < $thr) continue; $level = $p >= $thr * 2 ? 'Alta' : 'Média'; }
                }
                $verb = $v['delta'] > 0 ? 'subiu' : 'desceu';
                $how = $v['deltaPct'] !== null && $unit !== 'pts' && $unit !== '%' ? "$verb " . round(abs($v['deltaPct'])) . '%' : "$verb " . self::fmt($abs, $unit);
                $out[] = ['level' => $level, 'scope' => $s['id'], 'scopeName' => $s['name'], 'key' => $k, 'label' => $v['label'], 'current' => $v['current'], 'previous' => $v['previous'],
                    'deltaPct' => $v['deltaPct'], 'text' => "{$v['label']} $how em $days dias (" . self::fmt($v['previous'], $unit) . ' → ' . self::fmt($v['current'], $unit) . ')' . ($s['id'] ? " — {$s['name']}" : ' — Grupo')];
            }
        }
        $cash = self::view($series['0|cash'] ?? null, 'cash', $days);
        if ($cash && $cash['current'] < 0) array_unshift($out, ['level' => 'Alta', 'scope' => 0, 'scopeName' => 'Grupo', 'key' => 'cash', 'label' => 'Caixa', 'current' => $cash['current'],
            'previous' => $cash['previous'], 'deltaPct' => $cash['deltaPct'], 'text' => 'Caixa negativo: ' . self::fmt($cash['current'], 'AOA')]);
        $rank = ['Alta' => 0, 'Média' => 1];
        usort($out, fn ($a, $b) => [$rank[$a['level']], -abs($b['deltaPct'] ?? 1e9)] <=> [$rank[$b['level']], -abs($a['deltaPct'] ?? 1e9)]);
        return array_slice($out, 0, 30);
    }

    // ---------------------------------------------------------------- insights de IA
    private const FAIL = '/não foi possível|sobrecarregado|inválida|não está configurado|sem resposta|não consegui|sem permissão/iu';

    public static function generateInsight($user, int $days = 30): AiInsight
    {
        $series = self::loadSeries($days); $sc = self::scopes(); $round = fn ($n) => $n === null ? null : round($n * 10) / 10;
        $block = fn ($s) => array_values(array_map(fn ($v) => ['indicador' => $v['label'], 'unidade' => $v['unit'], 'atual' => $round($v['current']), 'anterior' => $round($v['previous']),
            'variacaoPct' => $round($v['deltaPct']), 'leitura' => ['melhor' => 'melhorou', 'pior' => 'piorou', 'igual' => 'igual'][$v['status']] ?? 'neutro'],
            array_filter(array_map(fn ($k) => self::view($series["{$s['id']}|$k"] ?? null, $k, $days), self::keysFor($s['id'])))));
        $comparable = false;
        foreach ($sc as $s) foreach (self::keysFor($s['id']) as $k) if ((self::view($series["{$s['id']}|$k"] ?? null, $k, $days)['previous'] ?? null) !== null) $comparable = true;
        if (!$comparable) abort(400, "Ainda não há histórico suficiente para comparar $days dias. Tente um período menor.");
        $analise = ['periodoDias' => $days, 'grupo' => $block($sc[0]), 'empresas' => array_map(fn ($s) => ['empresa' => $s['name'], 'indicadores' => $block($s)], array_slice($sc, 1)),
            'alertas' => array_column(self::alerts($days, $series), 'text')];
        $question = implode("\n", ["Com base APENAS nos dados em \"analise\", escreve de 5 a 7 insights curtos sobre a evolução nos últimos $days dias.",
            'Cada insight: uma frase com a variação em %, os valores (anterior → atual) e a empresa que mais contribuiu (compara as empresas). Dá prioridade ao que piorou.',
            'Não comentes indicadores com "anterior" nulo (sem histórico). Não inventes números nem causas que os dados não mostram.',
            'Formato: lista com "- ". Termina com a linha "Recomendações:" seguida de 2 ou 3 acções concretas, também em lista.']);
        $text = Ai::ask('CEO Agent', $question, ['analise' => $analise], $user);
        if (!$text || mb_strlen($text) < 80 || preg_match(self::FAIL, mb_substr($text, 0, 200))) abort(502, $text ?: 'Sem resposta da IA.');
        return AiInsight::create(['days' => $days, 'content' => $text, 'createdById' => $user->id ?? null]);
    }

    public static function spanDays(): int
    {
        $first = KpiSnapshot::orderBy('day')->first();
        return $first ? self::today() - self::utcDay($first->day) : 0;
    }
}
