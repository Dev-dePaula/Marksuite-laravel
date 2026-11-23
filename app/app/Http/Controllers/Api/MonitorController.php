<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\{ActivityLog, ErrorLog, User};
use App\Services\Monitor;
use Carbon\Carbon;
use Illuminate\Http\Request;

/** Monitorização da empresa-cliente: actividade dos utilizadores e erros (só Director-Geral, excepto /client-error). */
class MonitorController extends Controller
{
    private function guard(Request $r): void
    {
        if ($r->user()->role !== 'DIRECTOR_GERAL') abort(403, 'Apenas o Director-Geral acede à monitorização.');
    }

    private function days(Request $r): int { return min(max((int) $r->query('days', 7) ?: 7, 1), 90); }

    public function clientError(Request $r)
    {
        $d = $r->validate(['message' => 'required|string|max:500', 'stack' => 'nullable|string|max:8000']);
        Monitor::error($r, 'CLIENT', $d['message'], null, $d['stack'] ?? null);
        return response()->noContent();
    }

    public function summary(Request $r)
    {
        $this->guard($r);
        $d = $this->days($r); $from = now()->subDays($d);
        $users = User::where('tenantId', $r->user()->tenantId)->get(['username', 'name', 'role'])->keyBy('username');
        $rows = [];
        $row = function ($u) use (&$rows) { return $rows[$u] ??= ['username' => $u, 'logins' => 0, 'loginFails' => 0, 'visits' => 0, 'actions' => 0, 'errors' => 0, 'lastSeen' => null]; };
        foreach ($users as $u => $_) $row($u);
        $grouped = ActivityLog::where('createdAt', '>=', $from)->selectRaw('username, type, COUNT(*) as n')->groupBy('username', 'type')->get();
        foreach ($grouped as $g) {
            $k = $g->type === 'LOGIN' ? 'logins' : ($g->type === 'LOGIN_FAIL' ? 'loginFails' : ($g->type === 'VISIT' ? 'visits' : 'actions'));
            $rows[$g->username] = $row($g->username); $rows[$g->username][$k] = (int) $g->n;
        }
        foreach (ErrorLog::where('createdAt', '>=', $from)->whereNotNull('username')->selectRaw('username, COUNT(*) as n')->groupBy('username')->get() as $g) { $row($g->username); $rows[$g->username]['errors'] = (int) $g->n; }
        foreach (ActivityLog::selectRaw('username, MAX(createdAt) as m')->groupBy('username')->get() as $g) { $row($g->username); $rows[$g->username]['lastSeen'] = $g->m; }
        $out = array_map(fn ($x) => $x + ['name' => $users[$x['username']]->name ?? null, 'role' => $users[$x['username']]->role ?? null], array_values($rows));
        usort($out, fn ($a, $b) => ($b['logins'] + $b['visits'] + $b['actions']) <=> ($a['logins'] + $a['visits'] + $a['actions']));
        return ['days' => $d, 'users' => $out];
    }

    public function activity(Request $r)
    {
        $this->guard($r);
        return ActivityLog::where('createdAt', '>=', now()->subDays($this->days($r)))->when($r->query('username'), fn ($q, $u) => $q->where('username', (string) $u))
            ->when($r->query('type'), fn ($q, $t) => $q->where('type', (string) $t))->orderByDesc('id')->limit(200)->get();
    }

    public function errors(Request $r)
    {
        $this->guard($r);
        return ErrorLog::where('createdAt', '>=', now()->subDays($this->days($r)))->when($r->query('username'), fn ($q, $u) => $q->where('username', (string) $u))
            ->when($r->query('source'), fn ($q, $s) => $q->where('source', (string) $s))->orderByDesc('id')->limit(200)->get();
    }

    public function daily(Request $r)
    {
        $this->guard($r);
        $d = $this->days($r); $out = [];
        for ($i = $d - 1; $i >= 0; $i--) $out[now()->subDays($i)->toDateString()] = ['logins' => 0, 'visits' => 0, 'actions' => 0];
        $logs = ActivityLog::where('createdAt', '>=', now()->subDays($d))->whereIn('type', ['LOGIN', 'VISIT', 'ACTION'])->when($r->query('username'), fn ($q, $u) => $q->where('username', (string) $u))->get(['createdAt', 'type']);
        foreach ($logs as $l) {
            $k = Carbon::parse($l->createdAt)->toDateString();
            if (!isset($out[$k])) continue;
            $out[$k][$l->type === 'LOGIN' ? 'logins' : ($l->type === 'VISIT' ? 'visits' : 'actions')]++;
        }
        return collect($out)->map(fn ($v, $day) => ['day' => $day] + $v)->values();
    }
}
