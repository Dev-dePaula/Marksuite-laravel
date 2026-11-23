<?php

use App\Http\Middleware\CanWrite;
use App\Http\Middleware\TenantContext;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias(['tenant' => TenantContext::class, 'canwrite' => CanWrite::class]);
        $middleware->trustProxies(at: '*');
        $middleware->prepend(\App\Http\Middleware\BlockedIp::class);
        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);
        // API sem sessão: nunca redirecciona para /login, responde 401 JSON.
        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // A API responde sempre {error: "..."} (o frontend lê data.error).
        $isApi = fn (Request $r) => $r->is('api/*');

        $exceptions->render(function (ValidationException $e, Request $r) use ($isApi) {
            if (!$isApi($r)) return null;
            $msg = collect($e->errors())->map(fn ($m, $k) => "$k: " . $m[0])->implode('; ');
            return response()->json(['error' => $msg], 400);
        });
        $exceptions->render(function (AuthenticationException $e, Request $r) use ($isApi) {
            return $isApi($r) ? response()->json(['error' => 'Não autenticado.'], 401) : null;
        });
        $exceptions->render(function (ModelNotFoundException $e, Request $r) use ($isApi) {
            return $isApi($r) ? response()->json(['error' => 'Registo não encontrado.'], 404) : null;
        });
        $exceptions->render(function (NotFoundHttpException $e, Request $r) use ($isApi) {
            return $isApi($r) ? response()->json(['error' => 'Rota não encontrada.'], 404) : null;
        });
        $exceptions->render(function (HttpExceptionInterface $e, Request $r) use ($isApi) {
            if (!$isApi($r)) return null;
            $s = $e->getStatusCode();
            return response()->json(['error' => $s >= 500 ? 'Erro interno do servidor.' : ($e->getMessage() ?: 'Erro ' . $s)], $s);
        });
        $exceptions->render(function (\Throwable $e, Request $r) use ($isApi) {
            if (!$isApi($r) || config('app.debug')) return null;
            report($e);
            return response()->json(['error' => 'Erro interno do servidor.'], 500);
        });
    })->create();
