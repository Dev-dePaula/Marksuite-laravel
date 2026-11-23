<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\{Monitor, Provisioning, Security, Totp};
use App\Support\Perms;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class AuthController extends Controller
{
    private const MAX_TOKENS = 5;
    private const COMMON = ['password', 'password1', 'password123', '1234567890', '12345678910', 'qwertyuiop', 'qwerty1234', 'abc1234567', 'iloveyou12', 'admin12345',
        'marksuite1', 'marksuite123', 'angola2026', 'luanda1234', 'senha12345', 'segredo1234'];
    private static ?string $dummy = null;

    /** Política de palavra-passe: 10+ caracteres, letras e números, fora de listas triviais. */
    public static function passwordRule(): array
    {
        return ['required', 'string', Password::min(10)->letters()->numbers(), 'max:200',
            fn ($a, $v, $fail) => in_array(strtolower((string) $v), self::COMMON, true) ? $fail('palavra-passe demasiado comum.') : null];
    }

    private function userOut(User $u): array
    {
        return ['id' => $u->id, 'name' => $u->name, 'role' => $u->role, 'email' => $u->email, 'username' => $u->username, 'twoFactor' => (bool) $u->totpEnabled,
            'tenant' => ['id' => $u->tenantId, 'name' => $u->tenant?->name, 'plan' => $u->tenant?->plan, 'trialEndsAt' => $u->tenant?->trialEndsAt]];
    }

    /** Emite um token e mantém no máximo MAX_TOKENS sessões por utilizador (as mais antigas caem). */
    private function issue(User $u): string
    {
        $ids = $u->tokens()->orderByDesc('id')->pluck('id');
        if ($ids->count() >= self::MAX_TOKENS) $u->tokens()->whereIn('id', $ids->slice(self::MAX_TOKENS - 1))->delete();
        return $u->createToken('web')->plainTextToken;
    }

    /** Auto-registo de uma nova empresa-cliente: cria tenant + administrador + dados iniciais. */
    public function register(Request $r)
    {
        if (!filter_var(env('REGISTRATION_OPEN', true), FILTER_VALIDATE_BOOLEAN)) abort(403, 'Os registos estão fechados. Contacte o suporte.');
        $d = $r->validate([
            'companyName' => 'required|string|min:2|max:120', 'name' => 'required|string|min:2|max:120',
            'email' => 'required|email|max:190', 'password' => self::passwordRule(), 'demo' => 'sometimes|boolean',
        ]);
        if (User::where('email', strtolower($d['email']))->exists()) abort(409, 'Já existe uma conta com este email.');
        $res = Provisioning::create($d);
        Monitor::activity($r, 'LOGIN', $res['user']);
        Security::event('REGISTO', $r->ip(), $d['email']);
        return response()->json(['token' => $this->issue($res['user']), 'user' => $this->userOut($res['user'])], 201);
    }

    public function login(Request $r)
    {
        $d = $r->validate(['username' => 'required|string|max:190', 'password' => 'required|string|max:200', 'code' => 'sometimes|nullable|string|max:20']);
        $login = strtolower(trim($d['username']));
        $u = User::where('username', $login)->orWhere('email', $login)->first();

        if ($u && $u->lockedUntil && now()->lt($u->lockedUntil)) {
            Security::event('LOGIN_BLOQUEADO', $r->ip(), $login, 'conta bloqueada');
            return response()->json(['error' => 'Demasiadas tentativas. Tente novamente dentro de alguns minutos.'], 429);
        }

        // Tempo constante: faz sempre uma verificação de hash, exista o utilizador ou não (evita enumeração por tempo).
        self::$dummy ??= Hash::make(Str::random(16));
        $ok = Hash::check($d['password'], $u->passwordHash ?? self::$dummy) && $u !== null;

        if (!$ok || !$u->active) {
            if ($u) {
                $n = $u->failedLogins + 1;
                $lock = $n >= 5 ? now()->addMinutes(min(1440, 15 * (2 ** intdiv($n - 5, 5)))) : null; // 15 min, 30, 60... até 24 h
                $u->forceFill(['failedLogins' => $n, 'lockedUntil' => $lock])->save();
                if ($lock) Security::event('CONTA_BLOQUEADA', $r->ip(), $login, "$n falhas");
            }
            Monitor::activity($r, 'LOGIN_FAIL', $u, $login, $u?->tenantId);
            Security::event('LOGIN_FALHA', $r->ip(), $login);
            Security::strike($r, 'login', 12, 15, 60);
            return response()->json(['error' => 'Credenciais inválidas.'], 401);
        }
        if (!$u->tenant?->active) return response()->json(['error' => 'Conta suspensa. Contacte o suporte.'], 403);

        if ($u->totpEnabled) {
            $code = trim((string) ($d['code'] ?? ''));
            if ($code === '') return response()->json(['error' => 'Introduza o código de autenticação.', 'need2fa' => true], 401);
            if (!$this->checkSecondFactor($u, $code)) {
                Security::event('2FA_FALHA', $r->ip(), $login);
                Security::strike($r, 'login', 12, 15, 60);
                $n = $u->failedLogins + 1;
                $u->forceFill(['failedLogins' => $n, 'lockedUntil' => $n >= 5 ? now()->addMinutes(15) : null])->save();
                return response()->json(['error' => 'Código inválido.', 'need2fa' => true], 401);
            }
        }

        $u->forceFill(['failedLogins' => 0, 'lockedUntil' => null])->save();
        Monitor::activity($r, 'LOGIN', $u);
        return response()->json(['token' => $this->issue($u), 'user' => $this->userOut($u)]);
    }

    /** Código TOTP (anti-replay) ou código de recuperação (uso único). */
    private function checkSecondFactor(User $u, string $code): bool
    {
        $secret = Crypt::decryptString($u->totpSecret);
        if (($step = Totp::verify($secret, $code, (int) $u->totpLastStep)) !== null) {
            $u->forceFill(['totpLastStep' => $step])->save();
            return true;
        }
        $hashes = json_decode((string) $u->totpRecovery, true) ?: [];
        foreach ($hashes as $i => $h) {
            if (Hash::check(strtoupper($code), $h)) { unset($hashes[$i]); $u->forceFill(['totpRecovery' => json_encode(array_values($hashes))])->save(); return true; }
        }
        return false;
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

    /** Termina todas as outras sessões (útil se suspeitar de roubo de sessão). */
    public function logoutOthers(Request $r)
    {
        $r->user()->tokens()->where('id', '!=', $r->user()->currentAccessToken()->id)->delete();
        Security::event('SESSOES_TERMINADAS', $r->ip(), $r->user()->username);
        return response()->noContent();
    }

    public function changePassword(Request $r)
    {
        $d = $r->validate(['current' => 'required|string|max:200', 'password' => self::passwordRule()]);
        $u = $r->user();
        if (!Hash::check($d['current'], $u->passwordHash)) { Security::strike($r, 'login', 12, 15, 60); abort(403, 'Palavra-passe actual incorrecta.'); }
        $u->forceFill(['passwordHash' => Hash::make($d['password']), 'passwordChangedAt' => now()])->save();
        $u->tokens()->where('id', '!=', $u->currentAccessToken()->id)->delete(); // invalida as outras sessões
        Security::event('SENHA_ALTERADA', $r->ip(), $u->username);
        return response()->noContent();
    }

    // ---- 2FA (TOTP) ----
    public function twoFactorSetup(Request $r)
    {
        $u = $r->user();
        if ($u->totpEnabled) abort(409, 'A autenticação em dois passos já está activa.');
        $secret = Totp::secret();
        $u->forceFill(['totpSecret' => Crypt::encryptString($secret)])->save();
        return ['secret' => $secret, 'uri' => Totp::uri($secret, $u->email)];
    }

    public function twoFactorEnable(Request $r)
    {
        $d = $r->validate(['code' => 'required|string|max:20']);
        $u = $r->user();
        if ($u->totpEnabled || !$u->totpSecret) abort(409, 'Inicie a configuração primeiro.');
        $step = Totp::verify(Crypt::decryptString($u->totpSecret), $d['code']);
        if ($step === null) abort(422, 'Código inválido. Verifique a hora do telemóvel e tente de novo.');
        $plain = collect(range(1, 8))->map(fn () => strtoupper(Str::random(5) . '-' . Str::random(5)))->all();
        $u->forceFill(['totpEnabled' => true, 'totpLastStep' => $step, 'totpRecovery' => json_encode(array_map(fn ($c) => Hash::make($c), $plain))])->save();
        Security::event('2FA_ACTIVADO', $r->ip(), $u->username);
        return ['recoveryCodes' => $plain];
    }

    public function twoFactorDisable(Request $r)
    {
        $d = $r->validate(['password' => 'required|string|max:200', 'code' => 'required|string|max:20']);
        $u = $r->user();
        if (!Hash::check($d['password'], $u->passwordHash) || !$u->totpEnabled || !$this->checkSecondFactor($u, $d['code'])) {
            Security::strike($r, 'login', 12, 15, 60);
            abort(403, 'Palavra-passe ou código incorrectos.');
        }
        $u->forceFill(['totpEnabled' => false, 'totpSecret' => null, 'totpRecovery' => null, 'totpLastStep' => 0])->save();
        Security::event('2FA_DESACTIVADO', $r->ip(), $u->username);
        return response()->noContent();
    }

    // ---- utilizadores da empresa (só Director-Geral) ----
    public function users()
    {
        return User::where('tenantId', request()->user()->tenantId)->orderBy('id')->get(['id', 'username', 'email', 'name', 'role', 'active', 'isOwner', 'totpEnabled']);
    }

    public function createUser(Request $r)
    {
        $d = $r->validate(['name' => 'required|string|min:2|max:120', 'email' => 'required|email|max:190',
            'password' => self::passwordRule(), 'role' => ['required', Rule::in(Perms::ROLES)]]);
        if (User::where('email', strtolower($d['email']))->exists()) abort(409, 'Já existe uma conta com este email.');
        $u = User::create(['tenantId' => $r->user()->tenantId, 'username' => Provisioning::uniqueUsername($d['email']), 'email' => strtolower($d['email']),
            'name' => $d['name'], 'passwordHash' => Hash::make($d['password']), 'role' => $d['role'], 'passwordChangedAt' => now()]);
        return response()->json($u->only('id', 'username', 'email', 'name', 'role', 'active', 'isOwner'), 201);
    }

    public function updateUser(Request $r, int $id)
    {
        $u = User::where('tenantId', $r->user()->tenantId)->findOrFail($id);
        $rules = ['name' => 'sometimes|string|min:2|max:120', 'role' => ['sometimes', Rule::in(Perms::ROLES)], 'active' => 'sometimes|boolean', 'password' => self::passwordRule()];
        $rules['password'][0] = 'sometimes';
        $d = $r->validate($rules);
        if ($u->isOwner && (isset($d['active']) && !$d['active'] || isset($d['role']) && $d['role'] !== 'DIRECTOR_GERAL')) abort(409, 'O titular da conta não pode ser desactivado nem perder o perfil de Director-Geral.');
        if ($u->id === $r->user()->id && isset($d['active']) && !$d['active']) abort(409, 'Não pode desactivar a sua própria conta.');
        $revoke = isset($d['password']) || (isset($d['active']) && !$d['active']) || (isset($d['role']) && $d['role'] !== $u->role);
        if (isset($d['password'])) { $d['passwordHash'] = Hash::make($d['password']); $d['passwordChangedAt'] = now(); $d['failedLogins'] = 0; $d['lockedUntil'] = null; unset($d['password']); }
        $u->update($d);
        if ($revoke) $u->tokens()->delete(); // nova senha, desactivação ou mudança de perfil terminam as sessões
        return $u->only('id', 'username', 'email', 'name', 'role', 'active', 'isOwner');
    }
}
