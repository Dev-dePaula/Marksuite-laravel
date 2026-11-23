<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\{Course, Employee, Enrollment, Process, ProcessAction, Project};
use App\Services\Rules;
use Illuminate\Http\Request;

/** Projectos, pessoas, operações (processos) e universidade (cursos). */
class OpsController extends Controller
{
    // ---------- projectos ----------
    private function projRules(bool $p): array
    {
        $req = $p ? 'sometimes' : 'required';
        return ['name' => "$req|string|max:160", 'companyId' => [$req, 'integer', $this->ex('companies')], 'client' => 'sometimes|string|max:120',
            'ownerId' => [$req, 'integer', $this->ex('employees')], 'startDate' => "$req|date", 'dueDate' => "$req|date",
            'progress' => 'sometimes|integer|min:0|max:100', 'delayedDeps' => 'sometimes|integer|min:0|max:100'];
    }

    public function projects()
    {
        $cfg = Rules::settings();
        return Project::with(['company:id,name', 'owner:id,name'])->orderBy('dueDate')->get()->map(fn ($p) => Rules::withProjectMeta($p, $cfg));
    }

    public function storeProject(Request $r)
    {
        $d = $r->validate($this->projRules(false));
        if (strtotime($d['dueDate']) < strtotime($d['startDate'])) abort(400, 'dueDate: O prazo não pode ser anterior ao início.');
        return response()->json(Project::create($d), 201);
    }

    public function updateProject(Request $r, int $id)
    {
        $p = Project::findOrFail($id);
        $d = $r->validate($this->projRules(true));
        if (($d['progress'] ?? null) === 100) $d['completedAt'] = Rules::today();
        elseif (isset($d['progress'])) $d['completedAt'] = null;
        $p->update($d);
        return $p;
    }

    public function destroyProject(int $id)
    {
        Project::findOrFail($id)->delete();
        return response()->noContent();
    }

    // ---------- colaboradores ----------
    private function empRules(bool $p): array
    {
        $req = $p ? 'sometimes' : 'required';
        return ['name' => "$req|string|max:120", 'jobTitle' => "$req|string|max:120", 'companyId' => [$req, 'integer', $this->ex('companies')],
            'performance' => 'sometimes|integer|min:0|max:100', 'capacity' => 'sometimes|integer|min:0|max:200'];
    }

    public function employees()
    {
        return Employee::with('company:id,name')->orderBy('name')->get()->each(fn ($e) =>
            $e->setAttribute('status', $e->capacity > 100 ? 'Sobrecarga' : ($e->performance < 70 ? 'Em acompanhamento' : 'Activo')));
    }

    public function storeEmployee(Request $r) { return response()->json(Employee::create($r->validate($this->empRules(false))), 201); }

    public function updateEmployee(Request $r, int $id)
    {
        $e = Employee::findOrFail($id);
        $e->update($r->validate($this->empRules(true)));
        return $e;
    }

    public function destroyEmployee(int $id)
    {
        $e = Employee::findOrFail($id);
        if (Project::where('ownerId', $id)->exists() || Process::where('ownerId', $id)->exists()) abort(409, 'O colaborador é responsável por projectos ou processos.');
        $e->delete();
        return response()->noContent();
    }

    // ---------- processos (operações) ----------
    public function processes()
    {
        return Process::with(['company:id,name', 'owner:id,name'])->orderBy('id')->get()->each(fn ($p) => $p->setAttribute('status', Rules::processStatus($p)));
    }

    public function storeProcess(Request $r)
    {
        $d = $r->validate(['name' => 'required|string|max:160', 'companyId' => ['nullable', 'integer', $this->ex('companies')],
            'ownerId' => ['required', 'integer', $this->ex('employees')], 'sla' => 'required|string|max:40', 'execution' => 'sometimes|integer|min:0|max:100']);
        return response()->json(Process::create($d), 201);
    }

    public function improve(Request $r, int $id)
    {
        Process::findOrFail($id);
        $d = $r->validate(['action' => 'required|string|max:200', 'ownerId' => ['required', 'integer', $this->ex('employees')], 'dueDate' => 'required|date']);
        return response()->json(ProcessAction::create($d + ['processId' => $id]), 201);
    }

    public function processActions()
    {
        return ProcessAction::with(['process:id,name', 'owner:id,name'])->orderBy('dueDate')->get();
    }

    public function actionDone(int $id)
    {
        $a = ProcessAction::where('done', false)->findOrFail($id);
        $a->update(['done' => true]);
        $p = Process::find($a->processId);
        if ($p) $p->update(['execution' => min(100, $p->execution + 5)]);
        return ['ok' => true];
    }

    // ---------- universidade ----------
    public function courses()
    {
        return Course::withCount('enrollments')->orderBy('id')->get()->map(function ($c) {
            $a = $c->toArray(); $a['enrolled'] = $a['enrollments_count']; unset($a['enrollments_count']);
            return $a;
        });
    }

    public function storeCourse(Request $r)
    {
        $d = $r->validate(['name' => 'required|string|max:160', 'avgCompletion' => 'sometimes|integer|min:0|max:100']);
        if (Course::where('name', $d['name'])->exists()) abort(409, 'Já existe um curso com este nome.');
        return response()->json(Course::create($d), 201);
    }

    public function enroll(Request $r, int $id)
    {
        Course::findOrFail($id);
        $d = $r->validate(['employeeId' => ['required', 'integer', $this->ex('employees')]]);
        if (Enrollment::where('courseId', $id)->where('employeeId', $d['employeeId'])->exists()) abort(409, 'Colaborador já inscrito neste curso.');
        return response()->json(Enrollment::create($d + ['courseId' => $id]), 201);
    }
}
