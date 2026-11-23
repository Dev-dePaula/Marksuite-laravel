<?php

namespace App\Http\Middleware;

use App\Services\Monitor;
use Closure;
use Illuminate\Http\Request;

/** Regista acções (escritas) bem-sucedidas e respostas de erro por utilizador, para a página Monitorização. */
class TrackActivity
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);
        try {
            if ($request->is('api/monitor/*')) return $response;
            $s = $response->getStatusCode(); $u = $request->user();
            if ($u && $request->method() !== 'GET' && $s < 400) Monitor::activity($request, 'ACTION', $u);
            if ($u && $s >= 400 && !in_array($s, [401, 404])) {
                $msg = json_decode($response->getContent(), true)['error'] ?? "HTTP $s";
                Monitor::error($request, 'SERVER', $msg, $s);
            }
        } catch (\Throwable $e) { /* nunca falhar por causa do registo */ }
        return $response;
    }
}
