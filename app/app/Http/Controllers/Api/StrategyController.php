<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\{Automation, Decision, KeyResult, MemoryEntry, Objective, Task};
use App\Services\{Ai, Rules, Snapshot};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Centro de comando, decisões, memória corporativa, OKR, automações, definições, tarefas e IA. */
class StrategyController extends Controller
{
    private const AREAS = ['COMERCIAL', 'FINANCEIRO', 'PROJECTOS', 'PESSOAS', 'OPERACOES', 'DIRECCAO'];

    public function dashboard()
    {
        $s = Snapshot::build(); unset($s['raw']);
        return $s;
    }

    // ---------- decisões ----------
    public function decisions()
    {
        return Decision::where('status', 'PENDENTE')->orderByRaw('amount IS NULL')->orderByDesc('amount')->orderBy('id')->get();
    }

    public function storeDecision(Request $r)
    {
        $d = $r->validate(['title' => 'required|string|max:160', 'subtitle' => 'required|string|max:200', 'amount' => 'nullable|numeric|min:0',
            'area' => ['required', Rule::in(self::AREAS)], 'context' => 'required|string|max:2000']);
        return response()->json(Decision::create($d), 201);
    }

    public function decide(Request $r, int $id)
    {
        $d = $r->validate(['action' => ['required', Rule::in(['approve', 'reject', 'postpone'])], 'justification' => 'nullable|string|max:2000']);
        $dec = Decision::where('status', 'PENDENTE')->where('id', $id)->first();
        if (!$dec) abort(404, 'Decisão não encontrada ou já decidida.');
        if ($d['action'] === 'postpone') { $dec->update(['postponedUntil' => now()->addDay()]); return $dec; }
        $just = trim($d['justification'] ?? '');
        if ($just === '') abort(400, 'Indique a justificação — fica registada na Corporate Memory.');
        $ok = $d['action'] === 'approve';
        return DB::transaction(function () use ($dec, $ok, $just, $r) {
            $dec->update(['status' => $ok ? 'APROVADA' : 'RECUSADA', 'decidedById' => $r->user()->id, 'decidedAt' => now(), 'justification' => $just]);
            MemoryEntry::create(['title' => "{$dec->title} — {$dec->subtitle}", 'context' => $dec->context, 'responsible' => $r->user()->name,
                'approvedBy' => $r->user()->name, 'date' => Rules::today(), 'reason' => $just,
                'expected' => $ok ? 'Impacto positivo esperado no trimestre' : 'Evitar exposição desnecessária', 'area' => $dec->area, 'decisionId' => $dec->id]);
            return $dec;
        });
    }

    public function memory()
    {
        $rows = MemoryEntry::orderByDesc('date')->orderByDesc('id')->get();
        return ['total' => $rows->count(), 'measured' => $rows->where('outcome', '!=', 'EM_AVALIACAO')->count(),
            'above' => $rows->where('outcome', 'ACIMA')->count(), 'evaluating' => $rows->where('outcome', 'EM_AVALIACAO')->count(), 'items' => $rows];
    }

    public function memoryResult(Request $r, int $id)
    {
        $m = MemoryEntry::findOrFail($id);
        $m->update($r->validate(['actual' => 'required|string|max:2000', 'outcome' => ['required', Rule::in(['ACIMA', 'ABAIXO', 'EM_AVALIACAO'])]]));
        return $m;
    }

    // ---------- OKR ----------
    public function okrs()
    {
        return Objective::with('keyResults')->orderBy('id')->get()->each(fn ($o) =>
            $o->setAttribute('progress', $o->keyResults->count() ? (int) round($o->keyResults->avg('progress')) : 0));
    }

    public function storeOkr(Request $r)
    {
        $d = $r->validate(['title' => 'required|string|max:200', 'keyResults' => 'sometimes|array|max:10', 'keyResults.*' => 'string|max:200']);
        $o = Objective::create(['title' => $d['title']]);
        foreach ($d['keyResults'] ?? [] as $t) $o->keyResults()->create(['title' => $t]);
        return response()->json($o->load('keyResults'), 201);
    }

    public function updateKr(Request $r, int $id)
    {
        $k = KeyResult::findOrFail($id);
        $k->update($r->validate(['progress' => 'required|integer|min:0|max:100']));
        return $k;
    }

    public function destroyOkr(int $id)
    {
        $o = Objective::findOrFail($id);
        $o->keyResults()->delete();
        $o->delete();
        return response()->noContent();
    }

    // ---------- automações / definições ----------
    public function automations() { return Automation::orderBy('id')->get(); }

    public function toggleAutomation(Request $r, int $id)
    {
        $a = Automation::findOrFail($id);
        $a->update($r->validate(['enabled' => 'required|boolean']));
        return $a;
    }

    public function settings() { return Rules::settings(); }

    public function saveSettings(Request $r)
    {
        $d = $r->validate(['prop' => 'sometimes|integer|min:1|max:90', 'risk' => 'sometimes|integer|min:0|max:100', 'cob' => 'sometimes|integer|min:0|max:90',
            'health' => 'sometimes|integer|min:0|max:100', 'openingCash' => 'sometimes|numeric']);
        foreach ($d as $k => $v) Rules::setSetting($k, $v);
        return Rules::settings();
    }

    // ---------- tarefas ----------
    public function storeTask(Request $r)
    {
        $d = $r->validate(['title' => 'required|string|max:200', 'notes' => 'nullable|string|max:2000', 'assigneeId' => ['required', 'integer', $this->ex('employees')],
            'dueDate' => 'nullable|date', 'source' => 'sometimes|string|max:40']);
        $d['source'] ??= 'manual';
        return response()->json(Task::create($d + ['createdById' => $r->user()->id]), 201);
    }

    public function tasks() { return Task::where('done', false)->with('assignee:id,name')->orderByDesc('id')->limit(100)->get(); }

    // ---------- IA ----------
    public function ask(Request $r)
    {
        $d = $r->validate(['agent' => 'sometimes|string|max:40', 'question' => 'required|string|max:1000']);
        return ['answer' => Ai::ask($d['agent'] ?? 'CEO Agent', $d['question'], Snapshot::build(), $r->user())];
    }
}
