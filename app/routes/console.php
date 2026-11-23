<?php

use App\Models\Tenant;
use App\Services\{Analytics, Notify};
use App\Support\Tenancy;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

/**
 * Tarefas periódicas por empresa-cliente: captura diária de KPIs (Central de Análise) e lembretes de vencimento.
 * Em produção, ligar o agendador do Laravel:  * * * * * php artisan schedule:run
 */
Artisan::command('marksuite:daily', function () {
    foreach (Tenant::where('active', true)->get() as $t) {
        Tenancy::set($t->id);
        try {
            Analytics::freshen();
            $r = Notify::runDue();
            $this->line("[{$t->slug}] KPIs actualizados; lembretes: " . json_encode($r));
        } catch (\Throwable $e) {
            report($e);
            $this->error("[{$t->slug}] " . $e->getMessage());
        } finally {
            Tenancy::set(null);
        }
    }
})->purpose('Captura KPIs e envia lembretes de cobrança de todas as empresas-clientes');

Schedule::command('marksuite:daily')->hourly()->withoutOverlapping();
