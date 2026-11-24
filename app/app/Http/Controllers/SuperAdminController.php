<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Api\AuthController;
use App\Models\User;
use App\Services\Security;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/** Painel do dono da plataforma: empresas, utilizadores, leads, feedbacks e segurança (todas as empresas, sem isolamento). */
class SuperAdminController extends Controller
{
    private const PLANS = ['teste', 'basico', 'pro', 'empresarial'];
    private const LEAD_STATUS = ['NOVO', 'CONTACTADO', 'CONVERTIDO', 'PERDIDO'];
    private const FB_STATUS = ['NOVO', 'LIDO', 'RESOLVIDO'];

    // ---------- autenticação ----------
    public function loginForm(Request $r)
    {
        if ($r->session()->has('sa_id')) return redirect()->route('sa.dashboard');
        return view('superadmin.login');
    }

    public function login(Request $r)
    {
        $d = $r->validate(['email' => 'required|email|max:190', 'password' => 'required|string|max:200']);
        $sa = DB::table('super_admins')->where('email', strtolower($d['email']))->first();
        $fail = fn (string $m) => back()->withErrors(['email' => $m])->onlyInput('email');

        if ($sa && $sa->lockedUntil && now()->lt($sa->lockedUntil)) {
            Security::event('SA_BLOQUEADO', $r->ip(), $d['email'], 'conta bloqueada');
            return $fail('Demasiadas tentativas. Tente novamente dentro de alguns minutos.');
        }
        $hash = $sa->passwordHash ?? Hash::make(Str::random(16)); // tempo constante
        if (!Hash::check($d['password'], $hash) || !$sa) {
            if ($sa) {
                $n = $sa->failedLogins + 1;
                DB::table('super_admins')->where('id', $sa->id)->update(['failedLogins' => $n, 'lockedUntil' => $n >= 5 ? now()->addMinutes(min(1440, 15 * (2 ** intdiv($n - 5, 5)))) : null]);
            }
            Security::event('SA_LOGIN_FALHA', $r->ip(), $d['email']);
            Security::strike($r, 'superadmin', 8, 15, 120);
            return $fail('Credenciais inválidas.');
        }
        DB::table('super_admins')->where('id', $sa->id)->update(['failedLogins' => 0, 'lockedUntil' => null, 'lastLoginAt' => now(), 'lastLoginIp' => $r->ip()]);
        $r->session()->regenerate();
        $r->session()->put('sa_id', $sa->id);
        Security::event('SA_LOGIN', $r->ip(), $sa->email);
        return redirect()->route('sa.dashboard');
    }

    public function logout(Request $r)
    {
        $r->session()->invalidate();
        $r->session()->regenerateToken();
        return redirect()->route('sa.login');
    }

    public function password(Request $r)
    {
        $d = $r->validate(['current' => 'required|string|max:200', 'password' => AuthController::passwordRule()]);
        $sa = $r->attributes->get('sa');
        if (!Hash::check($d['current'], $sa->passwordHash)) return back()->with('err', 'Palavra-passe actual incorrecta.');
        DB::table('super_admins')->where('id', $sa->id)->update(['passwordHash' => Hash::make($d['password']), 'updated_at' => now()]);
        Security::event('SA_SENHA_ALTERADA', $r->ip(), $sa->email);
        return back()->with('ok', 'Palavra-passe alterada.');
    }

    // ---------- dashboard ----------
    public function dashboard()
    {
        $t = fn () => DB::table('tenants');
        $stats = [
            'tenants' => $t()->count(),
            'active' => $t()->where('active', 1)->count(),
            'suspended' => $t()->where('active', 0)->count(),
            'trial' => $t()->where('plan', 'teste')->where('active', 1)->count(),
            'paid' => $t()->where('plan', '!=', 'teste')->count(),
            'users' => DB::table('users')->count(),
            'new7' => $t()->where('created_at', '>=', now()->subDays(7))->count(),
            'leads' => DB::table('leads')->where('status', 'NOVO')->count(),
            'feedbacks' => DB::table('feedbacks')->where('status', 'NOVO')->count(),
            'rating' => round((float) DB::table('feedbacks')->whereNotNull('rating')->avg('rating'), 1),
            'active24' => DB::table('activity_logs')->where('createdAt', '>=', now()->subDay())->distinct()->count('tenantId'),
        ];
        $days = collect(range(13, 0))->map(function ($i) {
            $d = now()->subDays($i)->startOfDay();
            return ['label' => $d->format('d/m'), 'n' => DB::table('tenants')->whereBetween('created_at', [$d, $d->copy()->endOfDay()])->count()];
        });
        return view('superadmin.dashboard', [
            'stats' => $stats, 'days' => $days, 'max' => max(1, $days->max('n')),
            'recent' => DB::table('tenants')->orderByDesc('id')->limit(8)->get(),
            'leads' => DB::table('leads')->orderByDesc('id')->limit(6)->get(),
            'feedbacks' => DB::table('feedbacks')->orderByDesc('id')->limit(6)->get(),
            'expiring' => DB::table('tenants')->where('plan', 'teste')->where('active', 1)->whereBetween('trialEndsAt', [now(), now()->addDays(7)])->orderBy('trialEndsAt')->get(),
        ]);
    }

    // ---------- empresas ----------
    public function tenants(Request $r)
    {
        $q = DB::table('tenants')->select('tenants.*')
            ->selectRaw('(select count(*) from users where users.tenantId = tenants.id) as users_count')
            ->selectRaw('(select max(createdAt) from activity_logs where activity_logs.tenantId = tenants.id) as last_activity');
        if ($s = trim((string) $r->query('q'))) $q->where(fn ($w) => $w->where('name', 'like', "%$s%")->orWhere('slug', 'like', "%$s%")
            ->orWhereExists(fn ($x) => $x->from('users')->whereColumn('users.tenantId', 'tenants.id')->where('users.email', 'like', "%$s%")));
        if ($p = $r->query('plan')) $q->where('plan', $p);
        if (($a = $r->query('active')) !== null && $a !== '') $q->where('active', (int) $a);
        return view('superadmin.tenants', ['rows' => $q->orderByDesc('id')->paginate(25)->withQueryString(), 'plans' => self::PLANS]);
    }

    public function tenant(int $id)
    {
        $t = DB::table('tenants')->find($id) ?? abort(404);
        $count = fn (string $tbl) => DB::table($tbl)->where('tenantId', $id)->count();
        return view('superadmin.tenant', [
            't' => $t, 'plans' => self::PLANS,
            'users' => DB::table('users')->where('tenantId', $id)->orderBy('id')->get(),
            'metrics' => ['Empresas' => $count('companies'), 'Oportunidades' => $count('opportunities'), 'Projectos' => $count('projects'),
                'Funcionários' => $count('employees'), 'Tarefas' => $count('tasks')],
            'activity' => DB::table('activity_logs')->where('tenantId', $id)->orderByDesc('id')->limit(15)->get(),
            'errorLogs' => DB::table('error_logs')->where('tenantId', $id)->orderByDesc('id')->limit(8)->get(),
            'feedbacks' => DB::table('feedbacks')->where('tenantId', $id)->orderByDesc('id')->limit(10)->get(),
        ]);
    }

    public function updateTenant(Request $r, int $id)
    {
        $t = DB::table('tenants')->find($id) ?? abort(404);
        $d = $r->validate(['plan' => 'sometimes|in:' . implode(',', self::PLANS), 'active' => 'sometimes|boolean', 'extend' => 'sometimes|integer|min:1|max:365', 'name' => 'sometimes|string|min:2|max:120']);
        $u = array_intersect_key($d, array_flip(['plan', 'active', 'name']));
        if (isset($d['extend'])) {
            $base = $t->trialEndsAt && Carbon::parse($t->trialEndsAt)->isFuture() ? Carbon::parse($t->trialEndsAt) : now();
            $u['trialEndsAt'] = $base->addDays((int) $d['extend']);
        }
        if ($u) DB::table('tenants')->where('id', $id)->update($u + ['updated_at' => now()]);
        if (isset($d['active']) && !$d['active']) foreach (DB::table('users')->where('tenantId', $id)->pluck('id') as $uid) $this->revoke($uid);
        Security::event('SA_EMPRESA', $r->ip(), $r->attributes->get('sa')->email, "tenant $id: " . json_encode($u));
        return back()->with('ok', 'Empresa actualizada.');
    }

    // ---------- utilizadores ----------
    public function users(Request $r)
    {
        $q = DB::table('users')->join('tenants', 'tenants.id', '=', 'users.tenantId')
            ->select('users.id', 'users.name', 'users.email', 'users.role', 'users.active', 'users.isOwner', 'users.totpEnabled', 'users.lockedUntil', 'users.created_at', 'tenants.name as tenant', 'tenants.id as tid');
        if ($s = trim((string) $r->query('q'))) $q->where(fn ($w) => $w->where('users.name', 'like', "%$s%")->orWhere('users.email', 'like', "%$s%")->orWhere('tenants.name', 'like', "%$s%"));
        return view('superadmin.users', ['rows' => $q->orderByDesc('users.id')->paginate(30)->withQueryString()]);
    }

    public function userAction(Request $r, int $id)
    {
        $u = DB::table('users')->find($id) ?? abort(404);
        $a = $r->validate(['action' => 'required|in:toggle,unlock,logout,reset'])['action'];
        $msg = '';
        if ($a === 'toggle') {
            DB::table('users')->where('id', $id)->update(['active' => !$u->active]);
            if ($u->active) $this->revoke($id);
            $msg = $u->active ? 'Utilizador desactivado.' : 'Utilizador activado.';
        }
        if ($a === 'unlock') { DB::table('users')->where('id', $id)->update(['failedLogins' => 0, 'lockedUntil' => null]); $msg = 'Conta desbloqueada.'; }
        if ($a === 'logout') { $this->revoke($id); $msg = 'Sessões terminadas.'; }
        if ($a === 'reset') {
            $new = Str::password(14, true, true, false);
            DB::table('users')->where('id', $id)->update(['passwordHash' => Hash::make($new), 'passwordChangedAt' => now(), 'failedLogins' => 0, 'lockedUntil' => null]);
            $this->revoke($id);
            $msg = "Palavra-passe temporária de {$u->email}: $new — mostrada só agora; envie-a ao cliente e peça para a alterar.";
        }
        Security::event('SA_UTILIZADOR', $r->ip(), $r->attributes->get('sa')->email, "$a user $id ({$u->email})");
        return back()->with('ok', $msg);
    }

    private function revoke(int $userId): void
    {
        DB::table('personal_access_tokens')->where('tokenable_id', $userId)->where('tokenable_type', User::class)->delete();
    }

    // ---------- leads ----------
    public function leads(Request $r)
    {
        $q = DB::table('leads');
        if ($st = $r->query('status')) $q->where('status', $st);
        if ($s = trim((string) $r->query('q'))) $q->where(fn ($w) => $w->where('name', 'like', "%$s%")->orWhere('email', 'like', "%$s%")->orWhere('company', 'like', "%$s%"));
        if ($r->query('export') === 'csv') {
            $rows = $q->orderByDesc('id')->get();
            return response()->streamDownload(function () use ($rows) {
                $o = fopen('php://output', 'w');
                fwrite($o, "\xEF\xBB\xBF");
                fputcsv($o, ['Data', 'Nome', 'Email', 'Telefone', 'Empresa', 'Origem', 'Estado', 'Mensagem', 'Notas']);
                foreach ($rows as $l) fputcsv($o, [$l->created_at, $l->name, $l->email, $l->phone, $l->company, $l->source, $l->status, $l->message, $l->notes]);
                fclose($o);
            }, 'leads-' . now()->format('Ymd') . '.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
        }
        return view('superadmin.leads', ['rows' => $q->orderByDesc('id')->paginate(30)->withQueryString(), 'statuses' => self::LEAD_STATUS]);
    }

    public function updateLead(Request $r, int $id)
    {
        $d = $r->validate(['status' => 'required|in:' . implode(',', self::LEAD_STATUS), 'notes' => 'nullable|string|max:2000']);
        DB::table('leads')->where('id', $id)->update(['status' => $d['status'], 'notes' => $d['notes'] ?? null, 'updated_at' => now()]);
        return back()->with('ok', 'Lead actualizado.');
    }

    // ---------- feedbacks ----------
    public function feedbacks(Request $r)
    {
        $q = DB::table('feedbacks')->leftJoin('tenants', 'tenants.id', '=', 'feedbacks.tenantId')->select('feedbacks.*', 'tenants.name as tenant');
        if ($st = $r->query('status')) $q->where('feedbacks.status', $st);
        if ($ty = $r->query('type')) $q->where('feedbacks.type', $ty);
        return view('superadmin.feedbacks', ['rows' => $q->orderByDesc('feedbacks.id')->paginate(30)->withQueryString(), 'statuses' => self::FB_STATUS]);
    }

    public function updateFeedback(Request $r, int $id)
    {
        $d = $r->validate(['status' => 'required|in:' . implode(',', self::FB_STATUS)]);
        DB::table('feedbacks')->where('id', $id)->update(['status' => $d['status'], 'updated_at' => now()]);
        return back()->with('ok', 'Feedback actualizado.');
    }

    // ---------- segurança ----------
    public function security()
    {
        return view('superadmin.security', [
            'blocks' => DB::table('security_blocks')->where('until', '>', now())->orderByDesc('updatedAt')->get(),
            'events' => DB::table('security_events')->orderByDesc('id')->limit(60)->get(),
            'summary' => DB::table('security_events')->where('createdAt', '>=', now()->subDay())->selectRaw('type, COUNT(*) n')->groupBy('type')->orderByDesc('n')->get(),
        ]);
    }

    public function unban(Request $r)
    {
        Security::unban($r->validate(['ip' => 'required|string|max:64'])['ip']);
        return back()->with('ok', 'IP desbloqueado.');
    }

    // ---------- helper partilhado com o registo ----------
    public static function storeLead(array $d, ?string $ip, string $source = 'site'): void
    {
        DB::table('leads')->insert(['name' => $d['name'], 'email' => isset($d['email']) ? strtolower($d['email']) : null, 'phone' => $d['phone'] ?? null,
            'company' => $d['company'] ?? null, 'message' => $d['message'] ?? null, 'source' => $source, 'ip' => $ip, 'created_at' => now(), 'updated_at' => now()]);
    }
}
