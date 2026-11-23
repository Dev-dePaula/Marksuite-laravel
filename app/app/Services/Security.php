<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Cache, DB, Log};

/**
 * Defesa activa: regista eventos, conta "strikes" por IP e bloqueia automaticamente quem ataca
 * (força bruta, credential stuffing, scanners). Nunca falha o pedido por causa do registo.
 */
class Security
{
    public static function allowed(?string $ip): bool
    {
        $list = array_filter(array_map('trim', explode(',', (string) env('SECURITY_ALLOW_IPS', ''))));
        return $ip !== null && in_array($ip, $list, true);
    }

    public static function event(string $type, ?string $ip, ?string $username = null, ?string $detail = null): void
    {
        try {
            DB::table('security_events')->insert(['type' => $type, 'ip' => $ip, 'username' => $username ? mb_substr($username, 0, 190) : null,
                'detail' => $detail ? mb_substr($detail, 0, 300) : null, 'createdAt' => now()]);
            Log::channel('single')->warning("[security] $type ip=$ip user=$username $detail");
        } catch (\Throwable $e) { /* silencioso */ }
    }

    public static function isBlocked(?string $ip): bool
    {
        if (!$ip || self::allowed($ip)) return false;
        try {
            return Cache::remember("blk:$ip", 30, fn () => DB::table('security_blocks')->where('ip', $ip)->where('until', '>', now())->exists());
        } catch (\Throwable $e) { return false; }
    }

    public static function ban(string $ip, string $reason, int $minutes): void
    {
        if (self::allowed($ip)) return;
        try {
            $row = DB::table('security_blocks')->where('ip', $ip)->first();
            $until = now()->addMinutes($minutes);
            if ($row) DB::table('security_blocks')->where('ip', $ip)->update(['reason' => mb_substr($reason, 0, 160), 'hits' => $row->hits + 1, 'until' => $until, 'updatedAt' => now()]);
            else DB::table('security_blocks')->insert(['ip' => $ip, 'reason' => mb_substr($reason, 0, 160), 'until' => $until, 'createdAt' => now(), 'updatedAt' => now()]);
            Cache::forget("blk:$ip");
            self::event('IP_BANIDO', $ip, null, "$reason ({$minutes}min)");
        } catch (\Throwable $e) { /* silencioso */ }
    }

    public static function unban(string $ip): void
    {
        DB::table('security_blocks')->where('ip', $ip)->delete();
        Cache::forget("blk:$ip");
    }

    /** Conta uma infracção do IP numa janela; ao passar o limite, bloqueia-o. */
    public static function strike(Request $r, string $kind, int $limit, int $windowMin, int $banMin): int
    {
        $ip = $r->ip();
        if (!$ip) return 0;
        $key = "strike:$kind:$ip";
        Cache::add($key, 0, now()->addMinutes($windowMin));
        $n = (int) Cache::increment($key);
        if ($n >= $limit) { self::ban($ip, "Excesso de $kind ($n em {$windowMin}min)", $banMin); Cache::forget($key); }
        return $n;
    }
}
