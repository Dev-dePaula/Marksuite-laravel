<?php

use App\Services\Security;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

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
})->where('any', '^(?!api|up|app/).*$');
