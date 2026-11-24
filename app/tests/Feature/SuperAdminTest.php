<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SuperAdminTest extends TestCase
{
    use RefreshDatabase;

    private function sa(): void
    {
        $this->artisan('superadmin:create', ['email' => 'dono@marksuite.test', '--password' => 'SenhaForte2026x'])->assertSuccessful();
    }

    public function test_painel_exige_login_e_utilizador_do_erp_nao_entra(): void
    {
        $this->get('/superadmin')->assertRedirect('/superadmin/login');
        $this->get('/superadmin/empresas')->assertRedirect('/superadmin/login');
        $this->sa();
        $reg = $this->postJson('/api/auth/register', ['companyName' => 'Cliente Lda', 'name' => 'Ana', 'email' => 'ana@cliente.test', 'password' => 'Segura12345x'])->assertSuccessful();
        // o token do ERP nunca abre o painel
        $this->withHeader('Authorization', 'Bearer ' . $reg->json('token'))->get('/superadmin')->assertRedirect('/superadmin/login');
        // login do ERP não funciona no painel
        $this->post('/superadmin/login', ['email' => 'ana@cliente.test', 'password' => 'Segura12345x'])->assertSessionHasErrors('email');
        $this->post('/superadmin/login', ['email' => 'dono@marksuite.test', 'password' => 'errada'])->assertSessionHasErrors('email');
    }

    public function test_super_admin_gere_empresas_utilizadores_leads_e_feedbacks(): void
    {
        $this->sa();
        $reg = $this->postJson('/api/auth/register', ['companyName' => 'Cliente Lda', 'name' => 'Ana', 'email' => 'ana@cliente.test', 'password' => 'Segura12345x'])->assertSuccessful();
        $h = ['Authorization' => 'Bearer ' . $reg->json('token')];
        $this->postJson('/api/feedback', ['type' => 'ELOGIO', 'rating' => 5, 'message' => 'Muito bom!'], $h)->assertCreated();
        $this->postJson('/api/public/lead', ['name' => 'Rui', 'email' => 'rui@x.test', 'message' => 'Quero saber preços'])->assertCreated();
        $this->assertSame(1, DB::table('leads')->where('source', 'registo')->count()); // o registo gera lead
        $this->assertSame(1, DB::table('feedbacks')->whereNotNull('tenantId')->count());

        $this->post('/superadmin/login', ['email' => 'dono@marksuite.test', 'password' => 'SenhaForte2026x'])->assertRedirect('/superadmin');
        foreach (['/superadmin', '/superadmin/empresas', '/superadmin/utilizadores', '/superadmin/leads', '/superadmin/feedbacks', '/superadmin/seguranca', '/superadmin/leads?export=csv'] as $u) {
            $this->get($u)->assertOk();
        }
        $this->get('/superadmin')->assertSee('Cliente Lda');
        $tid = DB::table('tenants')->value('id');
        $this->get("/superadmin/empresas/$tid")->assertOk()->assertSee('ana@cliente.test');

        // suspender empresa: o cliente perde o acesso
        $this->post("/superadmin/empresas/$tid", ['active' => 0])->assertRedirect();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/auth/me', $h)->assertStatus(401); // sessões terminadas
        $this->post("/superadmin/empresas/$tid", ['active' => 1]);
        $this->post("/superadmin/empresas/$tid", ['plan' => 'pro'])->assertRedirect();
        $this->assertSame('pro', DB::table('tenants')->where('id', $tid)->value('plan'));

        // repor senha de utilizador
        $uid = DB::table('users')->value('id');
        $this->post("/superadmin/utilizadores/$uid", ['action' => 'reset'])->assertSessionHas('ok');
        $this->postJson('/api/auth/login', ['username' => 'ana@cliente.test', 'password' => 'Segura12345x'])->assertStatus(401);

        $lid = DB::table('leads')->value('id');
        $this->post("/superadmin/leads/$lid", ['status' => 'CONTACTADO', 'notes' => 'ligar amanhã'])->assertRedirect();
        $this->assertSame('CONTACTADO', DB::table('leads')->where('id', $lid)->value('status'));
    }

    public function test_formularios_publicos_e_honeypot(): void
    {
        $this->get('/contacto')->assertOk();
        $this->get('/feedback')->assertOk();
        $this->post('/contacto', ['name' => 'Bot', 'email' => 'b@x.test', 'website' => 'spam'])->assertStatus(204);
        $this->assertSame(0, DB::table('leads')->count());
        $this->post('/feedback', ['type' => 'PROBLEMA', 'message' => 'Erro ao emitir fatura'])->assertSessionHasNoErrors();
        $this->assertSame(1, DB::table('feedbacks')->count());
    }
}
