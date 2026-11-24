<?php

use App\Services\Security;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\{PublicCaptureController, SuperAdminController};
use App\Http\Middleware\SuperAdminAuth;

// Painel super admin e formulários públicos usam sessão em ficheiro (independente do ERP, que usa tokens).
if (request()->is('superadmin', 'superadmin/*', 'feedback', 'contacto')) {
    config(['session.driver' => 'file', 'session.cookie' => 'marksuite_sa', 'session.http_only' => true, 'session.same_site' => 'strict',
        'session.secure' => request()->isSecure(), 'session.lifetime' => 120]);
}

// ---------- formulários públicos (captura de leads e feedback) ----------
Route::get('/contacto', [PublicCaptureController::class, 'leadForm']);
Route::post('/contacto', [PublicCaptureController::class, 'lead'])->middleware('throttle:10,60');
Route::get('/feedback', [PublicCaptureController::class, 'feedbackForm']);
Route::post('/feedback', [PublicCaptureController::class, 'feedback'])->middleware('throttle:10,60');

// ---------- super admin ----------
Route::prefix('superadmin')->group(function () {
    Route::get('/login', [SuperAdminController::class, 'loginForm'])->name('sa.login');
    Route::post('/login', [SuperAdminController::class, 'login'])->middleware('throttle:10,15');
    Route::middleware(SuperAdminAuth::class)->group(function () {
        Route::get('/', [SuperAdminController::class, 'dashboard'])->name('sa.dashboard');
        Route::post('/logout', [SuperAdminController::class, 'logout']);
        Route::post('/password', [SuperAdminController::class, 'password']);
        Route::get('/empresas', [SuperAdminController::class, 'tenants'])->name('sa.tenants');
        Route::get('/empresas/{id}', [SuperAdminController::class, 'tenant'])->name('sa.tenant');
        Route::post('/empresas/{id}', [SuperAdminController::class, 'updateTenant']);
        Route::get('/utilizadores', [SuperAdminController::class, 'users'])->name('sa.users');
        Route::post('/utilizadores/{id}', [SuperAdminController::class, 'userAction']);
        Route::get('/leads', [SuperAdminController::class, 'leads'])->name('sa.leads');
        Route::post('/leads/{id}', [SuperAdminController::class, 'updateLead']);
        Route::get('/feedbacks', [SuperAdminController::class, 'feedbacks'])->name('sa.feedbacks');
        Route::post('/feedbacks/{id}', [SuperAdminController::class, 'updateFeedback']);
        Route::get('/seguranca', [SuperAdminController::class, 'security'])->name('sa.security');
        Route::post('/seguranca/desbloquear', [SuperAdminController::class, 'unban']);
    });
});

// O ERP (React) é servido a partir de public/app; "/" e qualquer rota fora de /api abrem a aplicação.
Route::get('/{any?}', function (Request $r) {
    // Honeypot: ninguém legítimo pede estes caminhos. Quem o faz é um scanner/atacante -> bloqueio automático.
    if (preg_match('~^(\.env|\.git|\.svn|\.aws|\.ssh|\.ht|wp-|xmlrpc|phpmyadmin|pma|myadmin|adminer|administrator|cgi-bin|vendor/|storage/|backup|dump\.sql|database\.sqlite|config\.php|phpinfo|info\.php|shell|eval-stdin|actuator|server-status|boaform|hnap1|console|telescope|horizon)~i', ltrim($r->path(), '/'))) {
        if ($ip = $r->ip()) {
            Security::event('HONEYPOT', $ip, null, 'GET /' . substr($r->path(), 0, 120));
            Security::ban($ip, 'Scanner: pedido a /' . substr($r->path(), 0, 60), 1440);
        }
        return response('Not found', 404);
    }
    $index = public_path('app/index.html');
    abort_unless(is_file($index), 503, 'Frontend não compilado. Execute: cd frontend && npm install && npm run build');
    return response()->file($index, ['Content-Type' => 'text/html; charset=utf-8']);
})->where('any', '^(?!api|up|app/|superadmin|contacto|feedback).*$');
