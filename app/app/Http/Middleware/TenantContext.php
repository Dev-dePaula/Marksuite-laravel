<?php

namespace App\Http\Middleware;

use App\Support\Tenancy;
use Closure;
use Illuminate\Http\Request;

/** Fixa o tenant do utilizador autenticado para todo o pedido (isolamento multi-empresa). */
class TenantContext
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if (!$user || !$user->active) return response()->json(['error' => 'Sessão inválida.'], 401);
        $tenant = $user->tenant;
        if (!$tenant || !$tenant->active) return response()->json(['error' => 'Conta suspensa. Contacte o suporte.'], 403);
        Tenancy::set((int) $user->tenantId);
        try {
            return $next($request);
        } finally {
            Tenancy::set(null);
        }
    }
}
