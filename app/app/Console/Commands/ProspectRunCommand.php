<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\ProspectController;
use App\Support\Tenancy;
use Illuminate\Console\Command;

/** Executa em segundo plano uma pesquisa de Prospecção IA (lançado pelo ProspectController). */
class ProspectRunCommand extends Command
{
    protected $signature = 'prospect:run {run : ID da pesquisa} {tenant : ID da empresa-cliente} {max=8}';
    protected $description = 'Executa uma pesquisa de prospecção por IA';

    public function handle(): int
    {
        Tenancy::set((int) $this->argument('tenant'));
        ProspectController::execute((int) $this->argument('run'), (int) $this->argument('max'));
        return self::SUCCESS;
    }
}
