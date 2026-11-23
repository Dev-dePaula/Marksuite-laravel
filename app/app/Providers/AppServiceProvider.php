<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // API autenticada: 240 pedidos/min por utilizador (ou IP). Trava scraping e abuso.
        RateLimiter::for('api', fn (Request $r) => Limit::perMinute(240)->by($r->user()?->id ?: $r->ip()));
        // Chamadas à IA (custo): 12/hora por utilizador.
        RateLimiter::for('ai', fn (Request $r) => Limit::perHour(12)->by('ai:' . ($r->user()?->id ?: $r->ip())));
        // Página pública de documentos (token): 60/min por IP — impede varrer tokens.
        RateLimiter::for('public', fn (Request $r) => Limit::perMinute(60)->by($r->ip()));
    }
}
