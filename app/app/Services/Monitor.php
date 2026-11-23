<?php

namespace App\Services;

use App\Models\{ActivityLog, ErrorLog};
use App\Support\Tenancy;
use Illuminate\Http\Request;

/** Registo de actividade e erros por empresa-cliente (porta de monitor.js). */
class Monitor
{
    public static function activity(Request $r, string $type, $user = null, ?string $username = null, ?int $tenantId = null): void
    {
        try {
            $tid = $tenantId ?? $user?->tenantId ?? Tenancy::id();
            if (!$tid) return;
            ActivityLog::withoutGlobalScopes()->create([
                'tenantId' => $tid, 'type' => $type, 'userId' => $user?->id, 'username' => substr($user?->username ?? $username ?? '?', 0, 60),
                'method' => $r->method(), 'path' => substr('/' . ltrim($r->path(), '/'), 0, 200), 'ip' => $r->ip(),
                'userAgent' => substr((string) $r->userAgent(), 0, 255),
            ]);
        } catch (\Throwable $e) { /* nunca falhar o pedido por causa do registo */ }
    }

    public static function error(Request $r, string $source, string $message, ?int $status = null, ?string $stack = null): void
    {
        try {
            $u = $r->user();
            $tid = $u?->tenantId ?? Tenancy::id();
            if (!$tid) return;
            ErrorLog::withoutGlobalScopes()->create([
                'tenantId' => $tid, 'source' => $source, 'userId' => $u?->id, 'username' => $u?->username, 'method' => $r->method(),
                'path' => substr('/' . ltrim($r->path(), '/'), 0, 200), 'status' => $status, 'message' => substr($message, 0, 500),
                'stack' => $stack ? substr($stack, 0, 8000) : null, 'ip' => $r->ip(), 'userAgent' => substr((string) $r->userAgent(), 0, 255),
            ]);
        } catch (\Throwable $e) { /* idem */ }
    }
}
