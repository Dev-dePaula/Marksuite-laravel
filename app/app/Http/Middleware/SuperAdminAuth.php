<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Protege /superadmin: sessão própria (independente dos tokens do ERP) e conta de super admin existente. */
class SuperAdminAuth
{
    public function handle(Request $request, Closure $next)
    {
        $id = $request->session()->get('sa_id');
        $sa = $id ? DB::table('super_admins')->find($id) : null;
        if (!$sa) { $request->session()->forget('sa_id'); return redirect()->route('sa.login'); }
        $request->attributes->set('sa', $sa);
        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        return $response;
    }
}
