<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MultiTenantTest extends TestCase
{
    use RefreshDatabase;

    /** No teste o mesmo processo serve vários "clientes": esquece o utilizador em cache entre pedidos (em produção cada pedido é novo). */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $this->app['auth']->forgetGuards();
        \App\Support\Tenancy::set(null);
        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }

    private function register(string $company, string $email, bool $demo = false): array
    {
        $r = $this->postJson('/api/auth/register', ['companyName' => $company, 'name' => 'Admin ' . $company, 'email' => $email, 'password' => 'segredo123', 'demo' => $demo]);
        $r->assertStatus(201);
        return ['h' => ['Authorization' => 'Bearer ' . $r->json('token')], 'user' => $r->json('user')];
    }

    public function test_cada_cliente_tem_o_seu_erp_isolado(): void
    {
        $a = $this->register('Alfa Lda', 'a@alfa.ao', true);
        $b = $this->register('Beta SA', 'b@beta.ao');

        // A tem dados de demonstração; B começa vazio mas funcional
        $da = $this->getJson('/api/dashboard', $a['h'])->assertOk()->json();
        $db = $this->getJson('/api/dashboard', $b['h'])->assertOk()->json();
        $this->assertGreaterThan(0, $da['openOpportunities']);
        $this->assertSame(0, $db['openOpportunities']);
        $this->assertCount(1, $db['companies']);
        $this->assertSame('Beta SA', $db['companies'][0]['name']);

        // B nunca vê dados de A
        $this->assertCount(0, $this->getJson('/api/opportunities', $b['h'])->json());
        $this->assertGreaterThan(0, count($this->getJson('/api/opportunities', $a['h'])->json()));
        $oppA = $this->getJson('/api/opportunities', $a['h'])->json()[0]['id'];
        $this->putJson("/api/opportunities/$oppA", ['title' => 'hack'], $b['h'])->assertStatus(404);
        $this->deleteJson("/api/projects/1", [], $b['h'])->assertStatus(404);

        // B não consegue referenciar uma empresa de A
        $compA = $da['companies'][0]['id'];
        $this->postJson('/api/opportunities', ['client' => 'X', 'title' => 'Y', 'companyId' => $compA, 'value' => 10], $b['h'])->assertStatus(400);

        // Sem token → 401
        $this->getJson('/api/dashboard')->assertStatus(401);
    }

    public function test_facturacao_completa_com_contabilidade_e_hash(): void
    {
        $a = $this->register('Gama Lda', 'g@gama.ao');
        $comp = $this->getJson('/api/companies', $a['h'])->json()[0]['id'];
        $this->putJson("/api/companies/$comp/fiscal", ['nif' => '5400123456', 'ivaRegime' => 'geral'], $a['h'])->assertOk();

        $doc = ['docType' => 'FT', 'companyId' => $comp, 'issueDate' => now()->toDateString(), 'terms' => '30 dias', 'emit' => true,
            'newClient' => ['name' => 'Cliente Um', 'nif' => '5001234567', 'email' => 'c@x.ao'],
            'lines' => [['description' => 'Serviço', 'qty' => 2, 'price' => 1000, 'ivaRate' => 14]]];
        $r = $this->postJson('/api/documents', $doc, $a['h'])->assertStatus(201)->json();
        $this->assertFalse($r['draft']);
        $this->assertSame(2280.0, (float) $r['amount']);
        $this->assertNotEmpty($r['hash']);
        $this->assertMatchesRegularExpression('/^FT \d{4}\/0001$/', $r['docNumber']);

        // 2.ª factura encadeia no hash da 1.ª
        $r2 = $this->postJson('/api/documents', $doc, $a['h'])->assertStatus(201)->json();
        $this->assertSame($r['hash'], $r2['prevHash']);
        $this->assertMatchesRegularExpression('/\/0002$/', $r2['docNumber']);

        // Recebimento parcial e total
        $this->postJson("/api/invoices/{$r['id']}/receipts", ['amount' => 1000, 'paymentMethod' => 'dinheiro'], $a['h'])->assertStatus(201);
        $paid = $this->postJson("/api/invoices/{$r['id']}/receipts", [], $a['h'])->assertStatus(201)->json();
        $this->assertNotNull($paid['paidAt']);

        // Contabilidade equilibrada e conformidade AGT sem falhas de integridade
        $bal = $this->getJson("/api/accounting/balancete?companyId=$comp", $a['h'])->assertOk()->json();
        $this->assertTrue($bal['balanced']);
        $c = collect($this->getJson("/api/compliance?companyId=$comp", $a['h'])->json('checks'))->keyBy('id');
        $this->assertSame('ok', $c['hash']['s']);
        $this->assertSame('ok', $c['seq']['s']);
        $this->assertSame('ok', $c['acct']['s']);

        // Anular emite nota de crédito e estorna o lançamento
        $this->postJson("/api/documents/{$r2['id']}/cancel", [], $a['h'])->assertOk();
        $this->assertTrue($this->getJson("/api/accounting/balancete?companyId=$comp", $a['h'])->json('balanced'));

        // Página pública por token assinado funciona sem sessão e é isolada pelo tenant do token
        $link = $this->getJson("/api/documents/{$r['id']}", $a['h'])->json('link');
        $path = parse_url($link, PHP_URL_PATH);
        $this->get($path)->assertOk()->assertSee('Cliente Um');
        $this->get(substr($path, 0, -3) . 'xyz')->assertStatus(404);
    }

    public function test_permissoes_por_perfil_e_utilizadores_do_cliente(): void
    {
        $a = $this->register('Delta Lda', 'd@delta.ao');
        $this->postJson('/api/users', ['name' => 'Marta', 'email' => 'cfo@delta.ao', 'password' => 'outrasenha1', 'role' => 'CFO'], $a['h'])->assertStatus(201);
        $login = $this->postJson('/api/auth/login', ['username' => 'cfo@delta.ao', 'password' => 'outrasenha1'])->assertOk();
        $cfo = ['Authorization' => 'Bearer ' . $login->json('token')];
        $this->assertSame('CFO', $login->json('user.role'));
        // CFO vê o mesmo tenant mas não pode escrever em Empresas
        $this->assertSame('Delta Lda', $this->getJson('/api/companies', $cfo)->json()[0]['name']);
        $this->postJson('/api/companies', ['name' => 'Nova', 'abbr' => 'NV'], $cfo)->assertStatus(403);
        $this->getJson('/api/monitor/summary', $cfo)->assertStatus(403);
        $this->getJson('/api/monitor/summary', $a['h'])->assertOk();
        // credenciais erradas
        $this->postJson('/api/auth/login', ['username' => 'cfo@delta.ao', 'password' => 'errada'])->assertStatus(401);
        // email duplicado
        $this->postJson('/api/auth/register', ['companyName' => 'Outra', 'name' => 'Xavier', 'email' => 'd@delta.ao', 'password' => 'segredo123'])->assertStatus(409);
    }

    public function test_modulos_principais_respondem(): void
    {
        $a = $this->register('Épsilon Lda', 'e@eps.ao', true);
        foreach (['/api/projects', '/api/employees', '/api/processes', '/api/decisions', '/api/memory', '/api/okrs', '/api/courses', '/api/automations', '/api/settings',
                     '/api/invoices', '/api/movements', '/api/documents', '/api/contacts', '/api/items', '/api/suppliers', '/api/expenses', '/api/billing-meta',
                     '/api/analytics/overview', '/api/analytics/alerts', '/api/analytics/insights', '/api/opportunities/funnel', '/api/late/config', '/api/notifications/config',
                     '/api/accounting/summary', '/api/accounting/dre', '/api/accounting/balanco', '/api/accounting/taxes', '/api/monitor/daily'] as $u) {
            $this->getJson($u, $a['h'])->assertOk();
        }
        $comp = $this->getJson('/api/companies', $a['h'])->json()[0]['id'];
        $this->getJson("/api/accounting/accounts?companyId=$comp", $a['h'])->assertOk();
        $this->getJson('/api/iva?month=' . now()->format('Y-m') . "&companyId=$comp", $a['h'])->assertOk();
        $ov = $this->getJson('/api/analytics/overview', $a['h'])->json();
        $this->assertNotEmpty($ov['kpis']);
        // avançar oportunidade até ganhar → memória corporativa
        $opp = collect($this->getJson('/api/opportunities', $a['h'])->json())->firstWhere('stage', 'FECHO');
        $this->postJson("/api/opportunities/{$opp['id']}/advance", [], $a['h'])->assertOk()->assertJsonPath('outcome', 'GANHA');
        // decidir
        $dec = $this->getJson('/api/decisions', $a['h'])->json()[0];
        $this->postJson("/api/decisions/{$dec['id']}/decide", ['action' => 'approve'], $a['h'])->assertStatus(400);
        $this->postJson("/api/decisions/{$dec['id']}/decide", ['action' => 'approve', 'justification' => 'ok'], $a['h'])->assertOk();
        // IA sem chave configurada devolve mensagem, não erro
        $this->postJson('/api/ai/ask', ['question' => 'Como estamos?'], $a['h'])->assertOk()->assertJsonStructure(['answer']);
    }
}
