<?php

namespace Tests\Feature;

use App\Models\{Prospect, ProspectRun};
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Http, Mail};
use Tests\TestCase;

class ProspectTest extends TestCase
{
    use RefreshDatabase;

    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $this->app['auth']->forgetGuards();
        Tenancy::set(null);
        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }

    private function register(string $company, string $email): array
    {
        $r = $this->postJson('/api/auth/register', ['companyName' => $company, 'name' => 'Admin', 'email' => $email, 'password' => 'Segredo12345', 'demo' => false])->assertStatus(201);
        return ['h' => ['Authorization' => 'Bearer ' . $r->json('token')], 'tenant' => $r->json('user.tenantId')];
    }

    public function test_prospeccao_pesquisa_pdf_envio_e_isolamento(): void
    {
        putenv('GEMINI_API_KEY=teste'); $_ENV['GEMINI_API_KEY'] = 'teste';
        config(['mail.default' => 'array']);
        $a = $this->register('Alfa Lda', 'a@alfa.ao');
        $b = $this->register('Beta SA', 'b@beta.ao');
        $companyId = $this->getJson('/api/companies', $a['h'])->json('0.id');

        $json = json_encode(['summary' => ['common' => ['x'], 'commonPains' => ['y'], 'opportunity' => 'z'],
            'companies' => [['name' => 'Construtora Kwanza', 'email' => 'geral@kwanza.ao', 'phone' => null, 'website' => 'kwanza.ao', 'city' => 'Luanda', 'sourceUrl' => 'https://kwanza.ao',
                'pains' => ['Facturação manual'], 'solutions' => ['Facturação AGT -> menos erros'], 'intro' => 'Olá']]]);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => "```json\n$json\n```"]]]]]])]);

        // a pesquisa corre após a resposta; no teste corre no fim do pedido
        $this->postJson('/api/prospect/runs', ['companyId' => $companyId, 'niche' => 'construção', 'location' => 'Luanda, Angola', 'max' => 5], $a['h'])->assertStatus(202);

        $runs = $this->getJson('/api/prospect/runs', $a['h'])->assertOk()->json();
        $this->assertCount(1, $runs);
        $run = $this->getJson('/api/prospect/runs/' . $runs[0]['id'], $a['h'])->assertOk()->json();
        $this->assertSame('CONCLUIDA', $run['status']);
        $this->assertCount(1, $run['prospects']);
        $pid = $run['prospects'][0]['id'];

        // PDF
        $pdf = $this->get("/api/prospect/$pid/pdf", $a['h'])->assertOk();
        $this->assertStringStartsWith('%PDF', $pdf->getContent());

        // envio -> oportunidade no CRM
        $this->postJson("/api/prospect/$pid/send", [], $a['h'])->assertOk()->assertJsonPath('status', 'ENVIADA');
        $opps = $this->getJson('/api/opportunities', $a['h'])->json();
        $this->assertSame('PROPOSTA', collect($opps)->firstWhere('client', 'Construtora Kwanza')['stage']);
        $this->postJson("/api/prospect/$pid/send", [], $a['h'])->assertStatus(400);
        $this->postJson("/api/prospect/$pid/reply", [], $a['h'])->assertOk()->assertJsonPath('status', 'RESPONDEU');

        // isolamento: B não vê nada de A
        $this->assertCount(0, $this->getJson('/api/prospect/runs', $b['h'])->json());
        $this->getJson('/api/prospect/runs/' . $runs[0]['id'], $b['h'])->assertStatus(404);
        $this->get("/api/prospect/$pid/pdf", $b['h'])->assertStatus(404);
    }
}
