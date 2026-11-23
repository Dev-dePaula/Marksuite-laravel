<?php

namespace App\Services;

/** TOTP (RFC 6238, SHA-1, 6 dígitos, 30 s) compatível com Google/Microsoft Authenticator e Authy. */
class Totp
{
    private const ALPHA = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function secret(): string
    {
        $bytes = random_bytes(20);
        $bits = '';
        foreach (str_split($bytes) as $c) $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        return implode('', array_map(fn ($b) => self::ALPHA[bindec($b)], str_split($bits, 5)));
    }

    private static function decode(string $b32): string
    {
        $bits = '';
        foreach (str_split(strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $b32))) as $c) $bits .= str_pad(decbin(strpos(self::ALPHA, $c)), 5, '0', STR_PAD_LEFT);
        $out = '';
        foreach (str_split($bits, 8) as $b) if (strlen($b) === 8) $out .= chr(bindec($b));
        return $out;
    }

    public static function code(string $secret, int $step): string
    {
        $h = hash_hmac('sha1', pack('J', $step), self::decode($secret), true);
        $o = ord($h[19]) & 0xf;
        $n = ((ord($h[$o]) & 0x7f) << 24) | (ord($h[$o + 1]) << 16) | (ord($h[$o + 2]) << 8) | ord($h[$o + 3]);
        return str_pad((string) ($n % 1000000), 6, '0', STR_PAD_LEFT);
    }

    /** Devolve o passo (time-step) validado, ou null. Janela de ±1 passo para tolerar relógios desfasados. */
    public static function verify(string $secret, string $code, int $minStep = 0): ?int
    {
        $code = preg_replace('/\s+/', '', $code);
        if (!preg_match('/^\d{6}$/', $code)) return null;
        $now = intdiv(time(), 30);
        for ($d = -1; $d <= 1; $d++) {
            $step = $now + $d;
            if ($step > $minStep && hash_equals(self::code($secret, $step), $code)) return $step;
        }
        return null;
    }

    public static function uri(string $secret, string $account, string $issuer = 'MarkSuite'): string
    {
        return 'otpauth://totp/' . rawurlencode("$issuer:$account") . "?secret=$secret&issuer=" . rawurlencode($issuer) . '&algorithm=SHA1&digits=6&period=30';
    }
}
