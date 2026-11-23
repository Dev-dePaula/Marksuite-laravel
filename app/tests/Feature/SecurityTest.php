<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\{Security, Totp};
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Cache, Crypt};
use Tests\TestCase;

class SecurityTest extends TestCase
{
    use RefreshDatabase;

    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $this->app['auth']->forgetGuards();
        Tenancy::set(null);
        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }

    private function register(string $email = 'a@alfa.ao', string $pw = 'Segredo12345'): array
    {
        $r = $this->postJson('/api/auth/register', ['companyName' => 'Alfa', 'name' => 'Admin', 'email' => $email, 'password' => $pw, 'demo' => false])->assertStatus(201);
        return ['h' => ['Authorization' => 'Bearer ' . $r->json('token')], 'token' => $r->json('token')];
    }

    public function test_cabecalhos_de_seguranca(): void
    {
        $r = $this->getJson('/api/status');
        $r->assertHeader('X-Frame-Options', 'DENY')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString("frame-ancestors 'none'", $r->headers->get('Content-Security-Policy'));
        $this->assertStringContainsString('no-store', $r->headers->get('Cache-Control'));
    }

    public function test_politica_de_palavra_passe(): void
    {
        foreach (['curta1', 'somenteletras', '1234567890123', 'password123'] as $pw) {
            $this->postJson('/api/auth/register', ['companyName' => 'X Lda', 'name' => 'Adm', 'email' => "x$pw@x.ao", 'password' => $pw])->assertStatus(400);
        }
    }

    public function test_bloqueio_de_conta_apos_falhas(): void
    {
        $this->register();
        for ($i = 0; $i < 5; $i++) $this->postJson('/api/auth/login', ['username' => 'a@alfa.ao', 'password' => 'errada12345'])->assertStatus(401);
        // sexta tentativa, mesmo com a senha certa, fica bloqueada
        $this->postJson('/api/auth/login', ['username' => 'a@alfa.ao', 'password' => 'Segredo12345'])->assertStatus(429);
        $this->artisan('security:unlock', ['email' => 'a@alfa.ao'])->assertExitCode(0);
        $this->postJson('/api/auth/login', ['username' => 'a@alfa.ao', 'password' => 'Segredo12345'])->assertOk();
    }

    public function test_ip_atacante_e_bloqueado_automaticamente(): void
    {
        for ($i = 0; $i < 12; $i++) $this->postJson('/api/auth/login', ['username' => "ninguem$i@x.ao", 'password' => 'errada12345']);
        $this->assertTrue(Security::isBlocked('127.0.0.1'));
        $this->postJson('/api/auth/login', ['username' => 'a@alfa.ao', 'password' => 'x'])->assertStatus(403);
        Security::unban('127.0.0.1');
        Cache::flush();
        $this->postJson('/api/auth/login', ['username' => 'a@alfa.ao', 'password' => 'x'])->assertStatus(401);
    }

    public function test_honeypot_bloqueia_scanner(): void
    {
        $this->get('/.env.backup')->assertStatus(404);
        $this->assertTrue(Security::isBlocked('127.0.0.1'));
        $this->get('/')->assertStatus(403);
    }

    public function test_2fa_fluxo_completo_e_anti_replay(): void
    {
        $a = $this->register();
        $setup = $this->postJson('/api/auth/2fa/setup', [], $a['h'])->assertOk()->json();
        $step = intdiv(time(), 30);
        $code = Totp::code($setup['secret'], $step);
        $this->postJson('/api/auth/2fa/enable', ['code' => '000000'], $a['h'])->assertStatus(422);
        $rec = $this->postJson('/api/auth/2fa/enable', ['code' => $code], $a['h'])->assertOk()->json('recoveryCodes');
        $this->assertCount(8, $rec);

        // login pede o 2.º factor; o mesmo código não pode ser reutilizado
        $this->postJson('/api/auth/login', ['username' => 'a@alfa.ao', 'password' => 'Segredo12345'])->assertStatus(401)->assertJsonPath('need2fa', true);
        $this->postJson('/api/auth/login', ['username' => 'a@alfa.ao', 'password' => 'Segredo12345', 'code' => $code])->assertStatus(401);
        $next = Totp::code($setup['secret'], $step + 1);
        $this->postJson('/api/auth/login', ['username' => 'a@alfa.ao', 'password' => 'Segredo12345', 'code' => $next])->assertOk();
        // código de recuperação: uso único
        $this->postJson('/api/auth/login', ['username' => 'a@alfa.ao', 'password' => 'Segredo12345', 'code' => $rec[0]])->assertOk();
        $this->postJson('/api/auth/login', ['username' => 'a@alfa.ao', 'password' => 'Segredo12345', 'code' => $rec[0]])->assertStatus(401);
        // o segredo nunca é exposto e está cifrado em repouso
        $u = User::where('email', 'a@alfa.ao')->first();
        $this->assertSame($setup['secret'], Crypt::decryptString($u->totpSecret));
        $this->assertArrayNotHasKey('totpSecret', $u->toArray());
    }

    public function test_mudar_senha_termina_outras_sessoes(): void
    {
        $a = $this->register();
        $other = $this->postJson('/api/auth/login', ['username' => 'a@alfa.ao', 'password' => 'Segredo12345'])->json('token');
        $this->postJson('/api/auth/password', ['current' => 'Segredo12345', 'password' => 'NovaSenha98765'], $a['h'])->assertNoContent();
        $this->getJson('/api/auth/me', $a['h'])->assertOk();                                   // sessão actual mantém-se
        $this->getJson('/api/auth/me', ['Authorization' => "Bearer $other"])->assertStatus(401); // as outras caem
    }
}
