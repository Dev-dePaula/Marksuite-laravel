<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\{AiInsight, KpiSnapshot};
use App\Services\{Ai, Analytics};
use Carbon\Carbon;
use Illuminate\Http\Request;

class AnalyticsController extends Controller
{
    private function days(Request $r, int $def = 30): int { return min(max((int) $r->query('days', $def) ?: $def, 1), 180); }

    public function overview(Request $r)
    {
        Analytics::freshen();
        $days = $this->days($r); $scope = max(0, (int) $r->query('company', 0));
        $series = Analytics::loadSeries($days); $first = KpiSnapshot::orderBy('day')->first();
        $kpis = array_values(array_filter(array_map(fn ($k) => Analytics::view($series["$scope|$k"] ?? null, $k, $days), Analytics::keysFor($scope))));
        return ['days' => $days, 'scope' => $scope, 'scopes' => Analytics::scopes(), 'firstDay' => $first ? Carbon::parse($first->day)->toDateString() : null,
            'hasComparison' => (bool) array_filter($kpis, fn ($k) => $k['previous'] !== null), 'kpis' => $kpis];
    }

    public function breakdown(Request $r)
    {
        $key = (string) $r->query('key');
        if (!isset(Analytics::KPIS[$key])) abort(400, 'Indicador inválido.');
        $days = $this->days($r); $series = Analytics::loadSeries($days); $rows = [];
        foreach (Analytics::scopes() as $s) {
            if ($s['id'] !== 0 && !empty(Analytics::KPIS[$key]['group'])) continue;
            $v = Analytics::view($series["{$s['id']}|$key"] ?? null, $key, $days);
            if ($v) $rows[] = ['scope' => $s['id'], 'name' => $s['name'], 'current' => $v['current'], 'previous' => $v['previous'], 'delta' => $v['delta'], 'deltaPct' => $v['deltaPct'], 'status' => $v['status']];
        }
        return ['key' => $key, 'label' => Analytics::KPIS[$key]['label'], 'unit' => Analytics::KPIS[$key]['unit'], 'days' => $days, 'rows' => $rows];
    }

    public function alerts(Request $r)
    {
        Analytics::freshen();
        $days = $this->days($r);
        return ['days' => $days, 'alerts' => Analytics::alerts($days, Analytics::loadSeries($days))];
    }

    public function history(Request $r)
    {
        $days = $this->days($r, 90);
        $name = collect(Analytics::scopes())->pluck('name', 'id');
        $from = Carbon::createFromTimestampUTC((Analytics::today() - $days) * 86400)->toDateString();
        return KpiSnapshot::where('day', '>=', $from)->orderBy('day')->orderBy('companyId')->get()
            ->filter(fn ($x) => isset(Analytics::KPIS[$x->key]))->map(fn ($x) => ['day' => Carbon::parse($x->day)->toDateString(), 'scope' => $name[$x->companyId] ?? "#{$x->companyId}",
                'kpi' => Analytics::KPIS[$x->key]['label'], 'unit' => Analytics::KPIS[$x->key]['unit'], 'value' => $x->value])->values();
    }

    public function insights() { return ['aiConfigured' => Ai::configured(), 'insight' => AiInsight::orderByDesc('id')->first()]; }

    public function generate(Request $r)
    {
        if (!in_array($r->user()->role, ['DIRECTOR_GERAL', 'CFO'])) abort(403, 'Apenas o Director-Geral e o CFO podem gerar análises.');
        if (!Ai::configured()) abort(400, 'O assistente de IA não está configurado no servidor.');
        $last = AiInsight::orderByDesc('id')->first();
        if ($last && $last->createdAt && $last->createdAt->diffInSeconds(now()) < 120) abort(429, 'Aguarde 2 minutos entre análises.');
        Analytics::freshen();
        $span = Analytics::spanDays();
        if ($span < 1) abort(400, 'O histórico começou hoje; ainda não há o que comparar.');
        $days = min(max((int) $r->input('days', 30) ?: 30, 1), 180);
        return response()->json(Analytics::generateInsight($r->user(), min($days, $span)), 201);
    }
}
