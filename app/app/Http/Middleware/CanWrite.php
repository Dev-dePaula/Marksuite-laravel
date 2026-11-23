<?php

namespace App\Http\Middleware;

use App\Support\Perms;
use Closure;
use Illuminate\Http\Request;

class CanWrite
{
    public function handle(Request $request, Closure $next, string $module)
    {
        if (!Perms::can($module, $request->user()->role)) {
            return response()->json(['error' => 'O seu perfil não tem permissão para esta acção.'], 403);
        }
        return $next($request);
    }
}
