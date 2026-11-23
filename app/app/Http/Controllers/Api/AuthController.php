<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\{Monitor, Provisioning};
use App\Support\Perms;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class AuthController extends Controller
{
    private function userOut(User $u): array
    {
        return ['id' => $u->id, 'name' => $u->name, 'role' => $u->role, 'email' => $u->email, 'username' => $u->username,
            'tenant' => ['id' => $u->tenantId, 'name' => $u->tenant?->name, 'plan' => $u->tenant?->plan, 'trialEndsAt' => $u->tenant?->trialEndsAt]];
    }

    /** Auto-registo de uma nova empresa-cliente: cria tenant + administrador + dados iniciais. */
    public function register(Request $r)
    {
        $d = $r->validate([
            'companyName' => 'required|string|min:2|max:120', 'name' => 'required|string|min:2|max:120',
            'email' => 'required|email|max:190', 'password' => 'required|string|min:8|max:200', 'demo' => 'sometimes|boolean',
        ]);
        if (User::where('email', strtolower($d['email']))->exists()) abort(409, 'Já existe uma conta com este email.');
        $res = Provisioning::create($d);
        Monitor::activity($r, 'LOGIN', $res['user']);
        return response()->json(['token' => $res['user']->createToken('web')->plainTextToken, 'user' => $this->userOut($res['user'])], 201);
    }

    public function login(Request $r)
    {
        $d = $r->validate(['username' => 'required|string|max:190', 'password' => 'required|string|max:200']);
        $login = strtolower(trim($d['username']));
        $u = User::where('username', $login)->orWhere('email', $login)->first();
        if (!$u || !$u->active || !Hash::check($d['password'], $u->passwordHash)) {
            Monitor::activity($r, 'LOGIN_FAIL', $u, $login, $u?->tenantId);
            return response()->json(['error' => 'Credenciais inválidas.'], 401);
        }
        if (!$u->tenant?->active) return response()->json(['error' => 'Conta suspensa. Contacte o suporte.'], 403);
        Monitor::activity($r, 'LOGIN', $u);
        return response()->json(['token' => $u->createToken('web')->plainTextToken, 'user' => $this->userOut($u)]);
    }

    public function me(Request $r)
    {
        Monitor::activity($r, 'VISIT', $r->user());
        return $this->userOut($r->user());
    }

    public function logout(Request $r)
    {
        $r->user()->currentAccessToken()?->delete();
        return response()->noContent();
    }

    // ---- utilizadores da empresa (só Director-Geral) ----
    public function users()
    {
        return User::where('tenantId', request()->user()->tenantId)->orderBy('id')->get(['id', 'username', 'email', 'name', 'role', 'active', 'isOwner']);
    }

    public function createUser(Request $r)
    {
        $d = $r->validate(['name' => 'required|string|min:2|max:120', 'email' => 'required|email|max:190',
            'password' => 'required|string|min:8|max:200', 'role' => ['required', Rule::in(Perms::ROLES)]]);
        if (User::where('email', strtolower($d['email']))->exists()) abort(409, 'Já existe uma conta com este email.');
        $u = User::create(['tenantId' => $r->user()->tenantId, 'username' => Provisioning::uniqueUsername($d['email']), 'email' => strtolower($d['email']),
            'name' => $d['name'], 'passwordHash' => Hash::make($d['password']), 'role' => $d['role']]);
        return response()->json($u->only('id', 'username', 'email', 'name', 'role', 'active', 'isOwner'), 201);
    }

    public function updateUser(Request $r, int $id)
    {
        $u = User::where('tenantId', $r->user()->tenantId)->findOrFail($id);
        $d = $r->validate(['name' => 'sometimes|string|min:2|max:120', 'role' => ['sometimes', Rule::in(Perms::ROLES)],
            'active' => 'sometimes|boolean', 'password' => 'sometimes|string|min:8|max:200']);
        if ($u->isOwner && (isset($d['active']) && !$d['active'] || isset($d['role']) && $d['role'] !== 'DIRECTOR_GERAL')) abort(409, 'O titular da conta não pode ser desactivado nem perder o perfil de Director-Geral.');
        if ($u->id === $r->user()->id && isset($d['active']) && !$d['active']) abort(409, 'Não pode desactivar a sua própria conta.');
        if (isset($d['password'])) { $d['passwordHash'] = Hash::make($d['password']); unset($d['password']); }
        $u->update($d);
        if (isset($d['active']) && !$d['active']) $u->tokens()->delete();
        return $u->only('id', 'username', 'email', 'name', 'role', 'active', 'isOwner');
    }
}
