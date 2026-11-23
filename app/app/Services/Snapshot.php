<?php

namespace App\Services;

use App\Models\{Company, Decision, Employee, HealthSnapshot, Invoice, Movement, Opportunity, Process, Project};
use Carbon\Carbon;

/** Estado do grupo calculado a partir da BD (porta de snapshot.js). */
class Snapshot
{
    public const STAGES = ['LEADS', 'QUALIFICACAO', 'PROPOSTA', 'NEGOCIACAO', 'FECHO'];
    public const FIN_TYPES = ['FT', 'FR', 'FA', 'ND', 'AF', 'FG', 'FC'];

    private static function mk($d): string { return Carbon::parse($d)->format('Y-m'); }

    public static function build(): array
    {
        $cfg = Rules::settings();
        $companies = Company::orderBy('id')->get();
        $opps = Opportunity::where('outcome', 'ABERTA')->with('company')->get();
        $projects = Project::with(['company', 'owner'])->get()->map(fn ($p) => Rules::withProjectMeta($p, $cfg));
        $inv = Invoice::where('draft', false)->whereNull('cancelledAt')->whereIn('docType', self::FIN_TYPES)->get()->map(fn ($i) => Rules::invoiceView($i));
        $movs = Movement::orderByDesc('date')->get();
        $employees = Employee::with('company')->get();
        $processes = Process::with(['company', 'owner'])->get();
        $decisions = Decision::where('status', 'PENDENTE')->orderBy('id')->get();
        $snaps = HealthSnapshot::orderBy('month')->get();

        $today = Rules::today();
        $receivables = $inv->filter(fn ($i) => $i->kind === 'RECEBER' && !$i->paidAt);
        $payables = $inv->filter(fn ($i) => $i->kind === 'PAGAR' && !$i->paidAt);
        $overdue = $receivables->filter(fn ($i) => $i->overdueDays > 0)->values();
        $active = $projects->filter(fn ($p) => $p->status !== 'Concluído')->values();
        $stale = $opps->filter(fn ($o) => in_array($o->stage, ['PROPOSTA', 'NEGOCIACAO']) && -Rules::daysTo($o->lastContact) >= $cfg['prop'])->values();

        $yearStart = Carbon::create($today->year, 1, 1, 0, 0, 0, 'UTC');
        $rev = $companies->mapWithKeys(fn ($c) => [$c->id => 0.0])->all();
        $cost = $rev;
        foreach ($movs as $m) {
            if ($m->date < $yearStart || !$m->companyId || !isset($rev[$m->companyId])) continue;
            if ($m->amount > 0 && $m->category === 'Recebimentos') $rev[$m->companyId] += $m->amount;
            if ($m->amount < 0) $cost[$m->companyId] += -$m->amount;
        }

        $companyRows = $companies->map(function ($c) use ($rev, $cost) {
            $r = $rev[$c->id];
            return [
                'id' => $c->id, 'name' => $c->name, 'abbr' => $c->abbr, 'color' => $c->color, 'health' => Rules::companyHealth($c),
                'revenue' => $r, 'margin' => $r > 0 ? (int) round((($r - $cost[$c->id]) / $r) * 100) : null,
                'ivaRegime' => $c->ivaRegime, 'nif' => $c->nif, 'address' => $c->address, 'email' => $c->email,
                'phone' => $c->phone, 'website' => $c->website, 'iban' => $c->iban,
                'dims' => ['Comercial' => $c->scoreComercial, 'Financeiro' => $c->scoreFinanceiro, 'Projectos' => $c->scoreProjectos,
                    'Pessoas' => $c->scorePessoas, 'Clientes' => $c->scoreClientes, 'Operações' => $c->scoreOperacoes],
            ];
        })->values();
        $groupHealth = $companyRows->count() ? (int) round($companyRows->avg('health')) : 0;

        $byMonth = [];
        foreach ($snaps as $s) $byMonth[self::mk($s->month)][] = $s->score;
        ksort($byMonth);
        $trend = [];
        foreach (array_slice($byMonth, -5, 5, true) as $k => $v) $trend[] = ['month' => $k, 'score' => (int) round(array_sum($v) / count($v))];
        $trend[] = ['month' => self::mk($today), 'score' => $groupHealth];
        $prev = count($trend) > 1 ? $trend[count($trend) - 2]['score'] : null;
        $healthDelta = $prev ? (int) round((($groupHealth - $prev) / $prev) * 100) : null;

        $cash = $cfg['openingCash'] + $movs->sum('amount');
        $flow = [];
        foreach ($movs as $m) {
            $k = self::mk($m->date);
            $flow[$k] ??= ['month' => $k, 'in' => 0, 'out' => 0];
            if ($m->amount >= 0) $flow[$k]['in'] += $m->amount; else $flow[$k]['out'] += -$m->amount;
        }
        ksort($flow);
        $cashFlow = array_values(array_slice($flow, -6));
        $in30 = $receivables->filter(fn ($i) => $i->daysToDue <= 30)->sum('amount');
        $out30 = $payables->filter(fn ($i) => $i->daysToDue <= 30)->sum('amount');

        $pipeline = $opps->sum('value');
        $weighted = $opps->sum(fn ($o) => $o->value * $o->probability / 100);
        $byStage = array_map(fn ($s) => ['stage' => $s, 'count' => $opps->where('stage', $s)->count(), 'value' => $opps->where('stage', $s)->sum('value')], self::STAGES);

        $overloaded = $employees->filter(fn ($e) => $e->capacity > 100)->values();
        $opsAvg = $processes->count() ? (int) round($processes->avg('execution')) : 0;
        $slaOk = $processes->count() ? (int) round($processes->filter(fn ($p) => $p->execution >= 85)->count() / $processes->count() * 100) : 0;

        $pri = [];
        if ($stale->count()) {
            $o = $stale->sortByDesc('value')->first(); $d = -Rules::daysTo($o->lastContact);
            $pri[] = ['key' => 'stale', 'area' => 'Comercial', 'level' => 'Alta', 'title' => "Cliente {$o->client}",
                'detail' => "Proposta de {$o->value} AOA sem resposta há $d dias.", 'hint' => 'Contacto directo com o decisor.',
                'ref' => ['type' => 'opportunity', 'id' => $o->id], 'since' => $d];
        }
        $risky = $active->filter(fn ($p) => $p->risk >= $cfg['risk'])->sortByDesc('risk')->values();
        if ($risky->count()) {
            $p = $risky->first();
            $pri[] = ['key' => 'project', 'area' => 'Projectos', 'level' => $p->status === 'Atrasado' ? 'Alta' : 'Média', 'title' => "Projecto {$p->name}",
                'detail' => "{$p->risk}% de risco de derrapagem; " . ($p->daysLeft < 0 ? 'atrasado ' . -$p->daysLeft . ' dias' : "faltam {$p->daysLeft} dias") . '.',
                'hint' => $p->delayedDeps ? "{$p->delayedDeps} dependências atrasadas." : 'Progresso abaixo do esperado.', 'ref' => ['type' => 'project', 'id' => $p->id]];
        }
        if ($overdue->count()) {
            $tot = $overdue->sum('amount'); $top = $overdue->sortByDesc('amount')->first();
            $pri[] = ['key' => 'overdue', 'area' => 'Financeiro', 'level' => 'Alta', 'title' => 'Cobrança crítica',
                'detail' => "$tot AOA vencidos ({$overdue->count()} documentos).", 'hint' => "Maior valor em atraso: {$top->counterparty}.",
                'ref' => ['type' => 'invoice', 'id' => $top->id]];
        }
        if ($decisions->count()) {
            $pri[] = ['key' => 'decisions', 'area' => 'Direcção', 'level' => 'Média', 'title' => 'Decisões pendentes',
                'detail' => "{$decisions->count()} aprovações aguardam decisão.", 'hint' => 'Decidir primeiro as de maior valor.', 'ref' => ['type' => 'decisions']];
        }
        if ($overloaded->count()) {
            $pri[] = ['key' => 'overload', 'area' => 'Pessoas', 'level' => 'Média', 'title' => 'Sobrecarga de equipa',
                'detail' => "{$overloaded->count()} colaborador(es) acima de 100%: " . $overloaded->pluck('name')->implode(', ') . '.',
                'hint' => 'Realocar tarefas ou reforçar equipa.', 'ref' => ['type' => 'employees']];
        }
        $weak = $companyRows->filter(fn ($c) => $c['health'] < $cfg['health'])->values();
        if ($weak->count()) {
            $pri[] = ['key' => 'health', 'area' => 'Direcção', 'level' => 'Média', 'title' => 'Saúde abaixo do mínimo',
                'detail' => $weak->map(fn ($c) => "{$c['name']} ({$c['health']})")->implode(', ') . " abaixo de {$cfg['health']}.",
                'hint' => 'Rever dimensões mais fracas.', 'ref' => ['type' => 'companies']];
        }

        $overdueTotal = $overdue->sum('amount');
        return [
            'cfg' => $cfg, 'generatedAt' => now()->toIso8601String(), 'companies' => $companyRows, 'groupHealth' => $groupHealth,
            'healthDelta' => $healthDelta, 'healthTrend' => $trend, 'revenueYtd' => $companyRows->sum('revenue'), 'cash' => $cash,
            'cashFlow' => $cashFlow, 'in30' => $in30, 'out30' => $out30, 'pipeline' => $pipeline, 'weighted' => $weighted, 'byStage' => $byStage,
            'openOpportunities' => $opps->count(), 'staleOpportunities' => $stale->count(),
            'projects' => ['active' => $active->count(), 'onTime' => $projects->where('status', 'No prazo')->count(),
                'atRisk' => $projects->where('status', 'Em risco')->count(), 'late' => $projects->where('status', 'Atrasado')->count()],
            'overdueTotal' => $overdueTotal, 'overdueCount' => $overdue->count(), 'headcount' => $employees->count(),
            'overloaded' => $overloaded->count(), 'opsAvg' => $opsAvg, 'slaOk' => $slaOk, 'pendingDecisions' => $decisions->count(),
            'priorities' => $pri,
            'briefing' => [
                count($pri) . ' assuntos prioritários precisam da sua atenção.',
                $risky->count() . ' projectos com risco de atraso.',
                $stale->count() . " propostas sem follow-up há mais de {$cfg['prop']} dias.",
                $overdue->count() ? "Cobrança vencida: $overdueTotal AOA em {$overdue->count()} documentos." : 'Sem cobranças vencidas.',
                $decisions->count() . ' decisões aguardam aprovação.',
            ],
            'raw' => [
                'projects' => $projects->map(fn ($p) => ['nome' => $p->name, 'empresa' => $p->company?->name, 'responsavel' => $p->owner?->name, 'progresso' => $p->progress, 'prazo' => $p->dueDate->toDateString(), 'estado' => $p->status, 'risco' => $p->risk])->values(),
                'opportunities' => $opps->map(fn ($o) => ['cliente' => $o->client, 'titulo' => $o->title, 'empresa' => $o->company?->name, 'valor' => $o->value, 'fase' => $o->stage, 'prob' => $o->probability, 'diasSemContacto' => -Rules::daysTo($o->lastContact)])->values(),
                'overdue' => $overdue->map(fn ($i) => ['cliente' => $i->counterparty, 'doc' => $i->docNumber, 'valor' => $i->amount, 'diasAtraso' => $i->overdueDays])->values(),
                'people' => $employees->map(fn ($e) => ['nome' => $e->name, 'empresa' => $e->company?->name, 'desempenho' => $e->performance, 'capacidade' => $e->capacity])->values(),
                'processes' => $processes->map(fn ($p) => ['nome' => $p->name, 'execucao' => $p->execution, 'estado' => Rules::processStatus($p)])->values(),
            ],
        ];
    }
}
