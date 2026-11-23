<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/** Cabeçalhos de segurança em todas as respostas (CSP, HSTS, anti-clickjacking, anti-sniffing, privacidade). */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);
        $h = $response->headers;
        $set = fn (string $k, string $v) => $h->has($k) ? null : $h->set($k, $v);

        $set('Content-Security-Policy', "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; "
            . "font-src 'self' https://fonts.gstatic.com data:; img-src 'self' data: blob:; connect-src 'self'; frame-src 'self' blob: https://www.youtube-nocookie.com; object-src 'none'; "
            . "base-uri 'self'; form-action 'self'; frame-ancestors 'none'; upgrade-insecure-requests");
        $set('X-Frame-Options', 'DENY');
        $set('X-Content-Type-Options', 'nosniff');
        $set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=(), interest-cohort=()');
        $set('Cross-Origin-Opener-Policy', 'same-origin');
        $set('Cross-Origin-Resource-Policy', 'same-origin');
        if ($request->isSecure()) $set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        if ($request->is('api/*')) $h->set('Cache-Control', 'no-store, private');
        $h->remove('X-Powered-By');
        return $response;
    }
}
