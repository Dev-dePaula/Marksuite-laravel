<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmptyAccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_conta_nova_nasce_vazia_e_dashboard_funciona(): void
    {
        $r = $this->postJson('/api/auth/register', ['companyName' => 'Vazia Lda', 'name' => 'Ana Teste', 'email' => 'ana@vazia.test', 'password' => 'Segura12345x']);
        $r->assertSuccessful();
        $h = ['Authorization' => 'Bearer ' . $r->json('token')];
        foreach (['/api/opportunities', '/api/invoices', '/api/employees'] as $u) $this->getJson($u, $h)->assertOk()->assertJsonCount(0);
        $this->getJson('/api/dashboard', $h)->assertOk();
        $this->getJson('/api/companies', $h)->assertOk()->assertJsonCount(1);
    }
}
