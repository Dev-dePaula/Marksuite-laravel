<?php

namespace App\Http\Middleware;

use App\Services\Security;
use Closure;
use Illuminate\Http\Request;

/** Recusa imediatamente IPs bloqueados (ataques detectados). Corre antes de tudo o resto. */
class BlockedIp
{
    public function handle(Request $request, Closure $next)
    {
        if (Security::isBlocked($request->ip())) {
            return response()->json(['error' => 'Acesso temporariamente bloqueado.'], 403)->header('Retry-After', '3600');
        }
        return $next($request);
    }
}
