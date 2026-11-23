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

// ================= Segurança: comandos de operação e resposta a incidentes =================
use App\Services\Security;
use Illuminate\Support\Facades\DB;

Artisan::command('security:status {--hours=24}', function () {
    $from = now()->subHours((int) $this->option('hours'));
    $this->info("Eventos de segurança desde {$from}:");
    foreach (DB::table('security_events')->where('createdAt', '>=', $from)->selectRaw('type, COUNT(*) n')->groupBy('type')->orderByDesc('n')->get() as $r) $this->line(sprintf('  %-22s %d', $r->type, $r->n));
    $this->info('Top IPs agressores:');
    foreach (DB::table('security_events')->where('createdAt', '>=', $from)->whereNotNull('ip')->selectRaw('ip, COUNT(*) n')->groupBy('ip')->orderByDesc('n')->limit(10)->get() as $r) $this->line(sprintf('  %-40s %d', $r->ip, $r->n));
    $this->info('IPs bloqueados agora:');
    foreach (DB::table('security_blocks')->where('until', '>', now())->orderByDesc('updatedAt')->get() as $b) $this->line("  {$b->ip}  até {$b->until}  ({$b->reason})");
})->purpose('Resumo de ataques detectados e IPs bloqueados');

Artisan::command('security:ban {ip} {minutes=1440} {reason=manual}', function () {
    Security::ban($this->argument('ip'), $this->argument('reason'), (int) $this->argument('minutes'));
    $this->info('IP bloqueado.');
})->purpose('Bloqueia manualmente um IP');

Artisan::command('security:unban {ip}', function () {
    Security::unban($this->argument('ip'));
    $this->info('IP desbloqueado.');
})->purpose('Remove o bloqueio de um IP');

Artisan::command('security:unlock {email}', function () {
    $n = DB::table('users')->where('email', strtolower($this->argument('email')))->update(['failedLogins' => 0, 'lockedUntil' => null]);
    $this->info($n ? 'Conta desbloqueada.' : 'Utilizador não encontrado.');
})->purpose('Desbloqueia uma conta bloqueada por tentativas falhadas');

/** Botão de pânico: termina TODAS as sessões e (opcionalmente) suspende o acesso até nova ordem. */
Artisan::command('security:lockdown {--maintenance : Coloca o sistema em manutenção (503) para todos}', function () {
    $n = DB::table('personal_access_tokens')->delete();
    Security::event('LOCKDOWN', null, null, "$n sessões terminadas");
    if ($this->option('maintenance')) Artisan::call('down', ['--retry' => 600]);
    $this->warn("LOCKDOWN: $n sessões terminadas" . ($this->option('maintenance') ? '; sistema em manutenção (php artisan up para repor).' : '.'));
})->purpose('Resposta a incidente: termina todas as sessões (e opcionalmente fecha o sistema)');

Artisan::command('security:backup {--keep=14}', function () {
    $db = config('database.connections.sqlite.database');
    if (!is_file($db)) return $this->error('Base SQLite não encontrada.');
    $dir = env('BACKUP_DIR', dirname(base_path()) . '/backups-marksuite');
    if (!is_dir($dir)) mkdir($dir, 0700, true);
    $file = $dir . '/db-' . now()->format('Ymd-His') . '.sqlite';
    DB::connection()->getPdo()->exec("VACUUM INTO '" . str_replace("'", "''", $file) . "'"); // cópia consistente com a base em uso
    chmod($file, 0600);
    $files = glob($dir . '/db-*.sqlite'); rsort($files);
    foreach (array_slice($files, (int) $this->option('keep')) as $old) unlink($old);
    $this->info("Backup criado: $file (" . count(array_slice($files, 0, (int) $this->option('keep'))) . ' mantidos)');
})->purpose('Cópia de segurança diária da base de dados (rotação)');

Schedule::command('security:backup')->dailyAt('03:15');
Schedule::call(fn () => DB::table('security_events')->where('createdAt', '<', now()->subDays(90))->delete())->daily();
Schedule::call(fn () => DB::table('security_blocks')->where('until', '<', now()->subDays(30))->delete())->daily();
