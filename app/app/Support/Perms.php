<?php

namespace App\Support;

/** Quem pode ESCREVER em cada módulo. Todos os perfis podem LER tudo, excepto onde indicado. */
class Perms
{
    public const ROLES = ['DIRECTOR_GERAL', 'CFO', 'DIRECTOR_COMERCIAL', 'DIRECTOR_PROJECTOS', 'DIRECTOR_PESSOAS'];

    public const WRITE = [
        'companies' => ['DIRECTOR_GERAL'],
        'opportunities' => ['DIRECTOR_GERAL', 'DIRECTOR_COMERCIAL'],
        'projects' => ['DIRECTOR_GERAL', 'DIRECTOR_PROJECTOS'],
        'invoices' => ['DIRECTOR_GERAL', 'CFO'],
        'billing' => ['DIRECTOR_GERAL', 'CFO'],
        'accounting' => ['DIRECTOR_GERAL', 'CFO'],
        'approveExpense' => ['DIRECTOR_GERAL'],
        'movements' => ['DIRECTOR_GERAL', 'CFO'],
        'employees' => ['DIRECTOR_GERAL', 'DIRECTOR_PESSOAS'],
        'processes' => ['DIRECTOR_GERAL', 'DIRECTOR_PROJECTOS', 'DIRECTOR_PESSOAS'],
        'decisions' => ['DIRECTOR_GERAL'],
        'okrs' => ['DIRECTOR_GERAL'],
        'courses' => ['DIRECTOR_GERAL', 'DIRECTOR_PESSOAS'],
        'settings' => ['DIRECTOR_GERAL'],
        'automations' => ['DIRECTOR_GERAL'],
        'tasks' => ['DIRECTOR_GERAL', 'CFO', 'DIRECTOR_COMERCIAL', 'DIRECTOR_PROJECTOS', 'DIRECTOR_PESSOAS'],
        'users' => ['DIRECTOR_GERAL'],
    ];

    public static function can(string $module, string $role): bool
    {
        return in_array($role, self::WRITE[$module] ?? [], true);
    }
}
