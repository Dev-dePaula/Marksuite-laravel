<?php

namespace App\Services;

use App\Models\{Automation, Company, Course, Decision, Employee, HealthSnapshot, Invoice, MemoryEntry, Movement, Objective, Opportunity, Process, Project, Setting, Tenant, User};
use App\Support\Tenancy;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Cria uma empresa-cliente (tenant) completa: conta, administrador, empresa, definições, automações
 * e (opcionalmente) dados de demonstração, para o dashboard ERP ficar logo utilizável.
 */
class Provisioning
{
    public const AUTOMATIONS = [
        ['followup', 'Follow-up automático de propostas sem resposta há 5 dias', true],
        ['cobranca', 'Alerta de cobrança 3 dias antes do vencimento', true],
        ['relatorio', 'Relatório executivo diário às 07h00', true],
        ['escalonamento', 'Escalonamento de projectos com risco > 60%', true],
        ['memoria', 'Registo automático de decisões na Corporate Memory', true],
        ['realocacao', 'Sugestão de realocação em caso de sobrecarga de equipa', false],
    ];

    /** @return array{tenant: Tenant, user: User, company: Company} */
    public static function create(array $d): array
    {
        return DB::transaction(function () use ($d) {
            $tenant = Tenant::create([
                'name' => $d['companyName'], 'slug' => self::slug($d['companyName']),
                'plan' => 'teste', 'trialEndsAt' => now()->addDays(30),
            ]);
            $user = User::create([
                'tenantId' => $tenant->id, 'username' => self::username($d['email']), 'email' => strtolower($d['email']),
                'name' => $d['name'], 'passwordHash' => Hash::make($d['password']), 'role' => 'DIRECTOR_GERAL', 'isOwner' => true,
            ]);

            Tenancy::set($tenant->id);
            try {
                $company = Company::create(['name' => $d['companyName'], 'abbr' => self::abbr($d['companyName']), 'color' => '#2563EB']);
                foreach (Rules::DEFAULTS as $k => $v) Setting::create(['key' => $k, 'value' => $v]);
                foreach (self::AUTOMATIONS as [$code, $label, $on]) Automation::create(['code' => $code, 'label' => $label, 'enabled' => $on]);
                Accounting::ensureChart($company->id);
                if (!empty($d['demo'])) self::demo($company, $user);
            } finally {
                Tenancy::set(null);
            }
            return compact('tenant', 'user', 'company');
        });
    }

    private static function slug(string $name): string
    {
        $base = Str::slug($name) ?: 'empresa';
        $slug = $base; $i = 2;
        while (Tenant::where('slug', $slug)->exists()) $slug = $base . '-' . $i++;
        return $slug;
    }

    private static function username(string $email): string
    {
        $base = preg_replace('/[^a-z0-9._-]/', '', strtolower(Str::before($email, '@'))) ?: 'user';
        $u = substr($base, 0, 50); $i = 2;
        while (User::where('username', $u)->exists()) $u = substr($base, 0, 50) . $i++;
        return $u;
    }

    private static function abbr(string $name): string
    {
        $w = preg_split('/\s+/', trim(Str::ascii($name)));
        $a = count($w) > 1 ? implode('', array_map(fn ($x) => $x[0], array_slice($w, 0, 3))) : substr($w[0], 0, 2);
        return strtoupper(substr($a, 0, 4)) ?: 'EM';
    }

    public static function uniqueUsername(string $email): string { return self::username($email); }

    /** Dados de demonstração (valores em AOA) para explorar todos os módulos. */
    public static function demo(Company $c, User $owner): void
    {
        $M = 1e6;
        $day = fn (int $o) => Carbon::today('UTC')->addDays($o);
        $mAgo = fn (int $n, int $dom = 15) => Carbon::today('UTC')->startOfMonth()->subMonths($n)->day($dom);

        $c->update(['scoreComercial' => 88, 'scoreFinanceiro' => 76, 'scoreProjectos' => 82, 'scorePessoas' => 90, 'scoreClientes' => 86, 'scoreOperacoes' => 80]);
        for ($i = 5; $i >= 1; $i--) HealthSnapshot::create(['companyId' => $c->id, 'month' => $mAgo($i, 1), 'score' => 84 - $i]);

        $months = max(1, (int) now()->month);
        for ($i = 0; $i < $months; $i++) {
            $d = Carbon::create(now()->year, $i + 1, 15, 0, 0, 0, 'UTC');
            if ($d->isFuture()) continue;
            Movement::create(['date' => $d, 'description' => 'Recebimentos', 'companyId' => $c->id, 'category' => 'Recebimentos', 'amount' => 48 * $M]);
            Movement::create(['date' => $d, 'description' => 'Custos operacionais', 'companyId' => $c->id, 'category' => 'Fornecedores', 'amount' => -(48 * $M * 0.78)]);
        }
        Movement::create(['date' => $day(-2), 'description' => 'Salários', 'category' => 'Pessoal', 'amount' => -12 * $M]);
        Movement::create(['date' => $day(-7), 'description' => 'Renda e utilities', 'category' => 'Estrutura', 'amount' => -2.5 * $M]);
        Setting::where('key', 'openingCash')->update(['value' => (string) (40 * $M)]);

        $emp = [];
        foreach ([['Ana Costa', 'Gestora de Projectos', 88, 95], ['Paulo Cabral', 'Director de Obra', 82, 110], ['Rui Domingos', 'Tech Lead', 91, 85],
                     ['Sónia Alves', 'Gestora Comercial', 94, 90], ['Célia Bento', 'Coordenadora de Formação', 86, 70], ['Marta Domingos', 'Analista Financeira', 90, 80]] as [$n, $j, $p, $cap]) {
            $emp[$n] = Employee::create(['name' => $n, 'jobTitle' => $j, 'companyId' => $c->id, 'performance' => $p, 'capacity' => $cap]);
        }

        foreach ([['Distribuidora Norte', 'Fornecimento e instalação', 58, 'PROPOSTA', 65, -6], ['Ministério das Obras', 'Projecto de infra-estrutura', 90, 'NEGOCIACAO', 55, -8],
                     ['KambaTech', 'Plataforma digital', 18.5, 'PROPOSTA', 60, -6], ['Banco Atlântico', 'Formação de equipas', 32, 'FECHO', 85, -1],
                     ['Refrigerantes do Sul', 'Operação logística', 46, 'QUALIFICACAO', 35, -3], ['Telecom Angola', 'Manutenção de rede', 45, 'NEGOCIACAO', 70, -2],
                     ['Energia Nacional', 'Fornecimento de equipamento', 80, 'LEADS', 20, -4], ['Grupo Zahara', 'Transporte e distribuição', 28, 'PROPOSTA', 50, -11]] as [$cl, $t, $v, $st, $pr, $lc]) {
            Opportunity::create(['client' => $cl, 'title' => $t, 'companyId' => $c->id, 'value' => $v * $M, 'stage' => $st, 'probability' => $pr, 'lastContact' => $day($lc)]);
        }

        foreach ([['Plataforma Logística', 'Refrigerantes do Sul', 'Ana Costa', -120, 5, 68, 3], ['Expansão Huíla', 'Energia Nacional', 'Paulo Cabral', -90, 25, 42, 2],
                     ['Sistema ERP', 'Interno', 'Rui Domingos', -150, 40, 85, 0], ['Centro de Formação', 'Interno', 'Célia Bento', -60, 80, 28, 2]] as [$n, $cl, $o, $s, $d, $pg, $dd]) {
            Project::create(['name' => $n, 'companyId' => $c->id, 'client' => $cl, 'ownerId' => $emp[$o]->id, 'startDate' => $day($s), 'dueDate' => $day($d), 'progress' => $pg, 'delayedDeps' => $dd]);
        }
        Project::create(['name' => 'Modernização de Frota', 'companyId' => $c->id, 'client' => 'Interno', 'ownerId' => $emp['Paulo Cabral']->id,
            'startDate' => $day(-200), 'dueDate' => $day(-20), 'progress' => 100, 'completedAt' => $day(-22)]);

        $n = 1180;
        foreach ([['KambaTech', -21, 22], ['Grupo Zahara', -13, 14.5], ['Telecom Angola', -6, 31], ['Banco Atlântico', 10, 9.5]] as [$cp, $due, $v]) {
            Invoice::create(['kind' => 'RECEBER', 'counterparty' => $cp, 'docNumber' => 'FT ' . now()->year . '/' . $n++, 'companyId' => $c->id, 'dueDate' => $day($due), 'amount' => $v * $M, 'subtotal' => $v * $M, 'issueDate' => $day($due - 30)]);
        }
        Invoice::create(['kind' => 'PAGAR', 'counterparty' => 'Fornecedor de materiais', 'docNumber' => 'FC ' . now()->year . '/330', 'companyId' => $c->id, 'dueDate' => $day(12), 'amount' => 8.8 * $M, 'subtotal' => 8.8 * $M, 'docType' => 'FC', 'issueDate' => $day(-5)]);

        foreach ([['Facturação mensal', 'Marta Domingos', '5 dias', 92], ['Aprovisionamento de material', 'Paulo Cabral', '10 dias', 74],
                     ['Onboarding de clientes', 'Sónia Alves', '3 dias', 96], ['Suporte técnico N2', 'Rui Domingos', '24 h', 81], ['Fecho contabilístico', 'Marta Domingos', '8 dias', 64]] as [$nm, $o, $sla, $ex]) {
            Process::create(['name' => $nm, 'companyId' => $c->id, 'ownerId' => $emp[$o]->id, 'sla' => $sla, 'execution' => $ex]);
        }

        foreach ([['Aprovar contrato', 'Cliente: Distribuidora Norte', 58 * $M, 'COMERCIAL', 'Contrato de fornecimento e instalação negociado. Margem prevista de 22%.'],
                     ['Autorizar orçamento', 'Projecto: Expansão Huíla', 20 * $M, 'FINANCEIRO', 'Reforço de orçamento por revisão de preços de materiais.'],
                     ['Validar contratação', '2 Comerciais', null, 'PESSOAS', 'Reforço da equipa comercial para sustentar o crescimento do pipeline.']] as [$t, $s, $a, $ar, $cx]) {
            Decision::create(['title' => $t, 'subtitle' => $s, 'amount' => $a, 'area' => $ar, 'context' => $cx]);
        }
        MemoryEntry::create(['title' => 'Contratar dois comerciais', 'context' => 'Pipeline a crescer 18% sem capacidade de follow-up.', 'responsible' => 'Sónia Alves',
            'approvedBy' => $owner->name, 'date' => $mAgo(8, 12), 'reason' => 'Aumentar cobertura comercial.', 'expected' => '+15% em oportunidades qualificadas em 6 meses',
            'actual' => '+21% em oportunidades qualificadas', 'outcome' => 'ACIMA', 'area' => 'PESSOAS']);

        foreach ([['Crescer 25% a receita', [['Receita YTD de 600M AOA', 78], ['Fechar 12 contratos acima de 50M', 58], ['Margem média ≥ 20%', 50]]],
                     ['Tornar a execução previsível', [['≤10% de projectos atrasados', 40], ['Desvio médio de prazo ≤ 5 dias', 52]]]] as [$t, $krs]) {
            $o = Objective::create(['title' => $t]);
            foreach ($krs as [$kt, $pg]) $o->keyResults()->create(['title' => $kt, 'progress' => $pg]);
        }
        foreach ([['Liderança e Gestão de Equipas', 86], ['Vendas Consultivas B2B', 74], ['Gestão de Projectos', 91], ['Fiscalidade Angolana Aplicada', 68]] as [$nm, $av]) {
            Course::create(['name' => $nm, 'avgCompletion' => $av]);
        }
    }
}
