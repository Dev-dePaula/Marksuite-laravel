<?php

namespace App\Services;

use Carbon\Carbon;

/** Lógica fiscal pura (porta de fiscal.js): tipos de documento, cálculo, valor por extenso, hash encadeado, prazos. */
class Fiscal
{
    public static function r2($n): float { return round((float) $n + 1e-9, 2); }

    /** fisc: documento fiscal; pay: gera dívida; autopaid: nasce paga; neg: valores negativos (NC). */
    public const TYPES = [
        'FT' => ['n' => 'Factura', 'mod' => 'facturas', 'fisc' => true, 'pay' => true],
        'FR' => ['n' => 'Factura-recibo', 'mod' => 'facturas', 'fisc' => true, 'pay' => false, 'autopaid' => true],
        'FA' => ['n' => 'Factura de adiantamento', 'mod' => 'facturas', 'fisc' => true, 'pay' => true],
        'ND' => ['n' => 'Nota de débito', 'mod' => 'facturas', 'fisc' => true, 'pay' => true],
        'AF' => ['n' => 'Autofacturação', 'mod' => 'facturas', 'fisc' => true, 'pay' => true],
        'FG' => ['n' => 'Factura global', 'mod' => 'facturas', 'fisc' => true, 'pay' => true],
        'NC' => ['n' => 'Nota de crédito', 'mod' => 'facturas', 'fisc' => true, 'pay' => false, 'neg' => true],
        'PF' => ['n' => 'Pró-forma', 'mod' => 'proformas', 'fisc' => false, 'pay' => false],
        'GT' => ['n' => 'Guia de transporte', 'mod' => 'guias', 'fisc' => false, 'pay' => false],
        'EN' => ['n' => 'Nota de encomenda', 'mod' => 'encomendas', 'fisc' => false, 'pay' => false],
    ];
    public const SALES_TYPES = ['FT', 'FR', 'FA', 'ND', 'AF', 'FG'];
    public const FIN_TYPES = ['FT', 'FR', 'FA', 'ND', 'AF', 'FG', 'FC'];
    public const REGIMES = ['geral' => 'Regime Geral', 'simplificado' => 'Regime Simplificado', 'exclusao' => 'Regime de Exclusão'];
    public const TERMS = ['Pronto pagamento', '15 dias', '30 dias', '45 dias', '60 dias', '90 dias'];

    public static function termDays($t): int { return (int) $t; }

    public static function calcDue(string $type, $date, ?string $terms): Carbon
    {
        $d = Carbon::parse($date)->startOfDay();
        return in_array($type, ['FR', 'GT']) ? $d : $d->addDays(self::termDays($terms));
    }

    /** Calcula um documento; devolve valores POSITIVOS (o sinal da NC é aplicado à parte, em `sg`). */
    public static function calc(array $o): array
    {
        $lines = $o['lines']; $regime = $o['regime'] ?? 'geral'; $type = $o['type'] ?? 'FT';
        $g = min(100, max(0, (float) ($o['gdiscPct'] ?? 0))); $rt = min(100, max(0, (float) ($o['retPct'] ?? 0)));
        $sum = $disc = $gd = $net = $tax = 0; $by = []; $rows = [];
        foreach ($lines as $l) {
            $gross = self::r2(((float) ($l['qty'] ?? 0)) * ((float) ($l['price'] ?? 0)));
            $dsc = self::r2($gross * ((float) ($l['discPct'] ?? 0)) / 100);
            $nl = self::r2($gross - $dsc);
            $n = self::r2($nl * (1 - $g / 100));
            $rate = $regime === 'geral' ? (float) ($l['ivaRate'] ?? 0) : 0.0;
            $t = self::r2($n * $rate / 100);
            $sum += $gross; $disc += $dsc; $gd += $nl - $n; $net += $n; $tax += $t;
            $k = (string) (float) $rate;
            $by[$k] ??= ['base' => 0, 'tax' => 0];
            $by[$k]['base'] = self::r2($by[$k]['base'] + $n); $by[$k]['tax'] = self::r2($by[$k]['tax'] + $t);
            $rows[] = array_merge($l, ['ivaRate' => $rate, 'gross' => $gross, 'net' => $n, 'tax' => $t, 'total' => self::r2($n + $t)]);
        }
        $net = self::r2($net); $tax = self::r2($tax);
        $ret = self::r2($net * $rt / 100);
        $cp = (self::TYPES[$type]['fisc'] ?? false) ? max(0, min(100, (float) ($o['cativoPct'] ?? 0))) : 0;
        $cativo = self::r2($tax * $cp / 100);
        $total = self::r2($net + $tax - $ret);
        return ['rows' => $rows, 'sum' => self::r2($sum), 'disc' => self::r2($disc), 'gdisc' => self::r2($gd), 'net' => $net, 'tax' => $tax, 'ret' => $ret,
            'total' => $total, 'by' => $by, 'cativo' => $cativo, 'cativoPct' => $cp, 'recv' => self::r2($total - $cativo),
            'sg' => (self::TYPES[$type]['neg'] ?? false) ? -1 : 1];
    }

    // ---------- valor por extenso ----------
    private const U1 = ['zero', 'um', 'dois', 'três', 'quatro', 'cinco', 'seis', 'sete', 'oito', 'nove', 'dez', 'onze', 'doze', 'treze', 'catorze', 'quinze', 'dezasseis', 'dezassete', 'dezoito', 'dezanove'];
    private const T1 = ['', '', 'vinte', 'trinta', 'quarenta', 'cinquenta', 'sessenta', 'setenta', 'oitenta', 'noventa'];
    private const H1 = ['', 'cento', 'duzentos', 'trezentos', 'quatrocentos', 'quinhentos', 'seiscentos', 'setecentos', 'oitocentos', 'novecentos'];

    private static function b1000(int $n): string
    {
        if ($n === 100) return 'cem';
        $h = intdiv($n, 100); $r = $n % 100; $p = [];
        if ($h) $p[] = self::H1[$h];
        if ($r) {
            if ($r < 20) $p[] = self::U1[$r];
            else { $t = self::T1[intdiv($r, 10)]; $u = $r % 10; $p[] = $u ? "$t e " . self::U1[$u] : $t; }
        }
        return implode(' e ', $p);
    }

    private static function ext(int $n): string
    {
        if ($n === 0) return 'zero';
        if ($n >= 1000000000) return (string) $n;
        $m = intdiv($n, 1000000); $t = intdiv($n % 1000000, 1000); $r = $n % 1000; $seg = [];
        if ($m) $seg[] = [$m === 1 ? 'um milhão' : self::b1000($m) . ' milhões', $m];
        if ($t) $seg[] = [$t === 1 ? 'mil' : self::b1000($t) . ' mil', $t];
        if ($r) $seg[] = [self::b1000($r), $r];
        $o = '';
        foreach ($seg as $i => $s) {
            if ($i === 0) $o = $s[0];
            else $o .= ($s[1] < 100 || $s[1] % 100 === 0) ? " e {$s[0]}" : " {$s[0]}";
        }
        return $o;
    }

    public static function words($value): string
    {
        $c = (int) round(abs((float) $value) * 100); $kz = intdiv($c, 100); $cs = $c % 100; $p = [];
        if ($kz > 0) $p[] = $kz === 1 ? 'um kwanza' : self::ext($kz) . ($kz % 1000000 === 0 ? ' de' : '') . ' kwanzas';
        if ($cs > 0) $p[] = self::ext($cs) . ($cs === 1 ? ' cêntimo' : ' cêntimos');
        if (!$p) $p[] = 'zero kwanzas';
        $s = implode(' e ', $p);
        return mb_strtoupper(mb_substr($s, 0, 1)) . mb_substr($s, 1);
    }

    // ---------- integridade (hash encadeado) ----------
    public static function signedText($date, string $number, $total, ?string $prevHash): string
    {
        return implode(';', [Carbon::parse($date)->toDateString(), $number, number_format(self::r2($total), 2, '.', ''), $prevHash ?: '0']);
    }

    public static function docHash($date, string $number, $total, ?string $prevHash): string
    {
        return hash('sha256', self::signedText($date, $number, $total, $prevHash));
    }

    /** Devolve os números dos documentos (mesmo tipo) cuja ligação ou hash não confere. */
    public static function auditChain($docs): array
    {
        $bad = []; $prev = null;
        foreach (collect($docs)->sortBy('eseq') as $d) {
            $pe = $prev ? $prev->hash : '0';
            $exp = self::docHash($d->issueDate, $d->docNumber, $d->grossTotal ?? $d->amount, $pe);
            if ($d->prevHash !== $pe || $d->hash !== $exp) $bad[] = $d->docNumber;
            $prev = $d;
        }
        return $bad;
    }

    // ---------- assinatura RSA opcional (certificação AGT) ----------
    public static function sign(string $text): ?array
    {
        $pem = env('SIGN_PRIVATE_KEY') ? str_replace('\n', "\n", env('SIGN_PRIVATE_KEY')) : (env('SIGN_PRIVATE_KEY_PATH') && is_file(env('SIGN_PRIVATE_KEY_PATH')) ? file_get_contents(env('SIGN_PRIVATE_KEY_PATH')) : null);
        if (!$pem) return null;
        $key = openssl_pkey_get_private($pem);
        if (!$key) return null;
        $algo = env('SIGN_ALGO', 'RSA-SHA256') === 'RSA-SHA1' ? OPENSSL_ALGO_SHA1 : OPENSSL_ALGO_SHA256;
        openssl_sign($text, $sig, $key, $algo);
        return ['signature' => base64_encode($sig), 'version' => env('SIGN_KEY_VERSION', '1')];
    }

    // ---------- calendário fiscal ----------
    private static function weekend(Carbon $d): bool { return in_array($d->dayOfWeekIso, [6, 7]); }

    public static function workDays($a, $b): int
    {
        $n = 0; $d = Carbon::parse($a)->startOfDay(); $e = Carbon::parse($b)->startOfDay();
        while ($d < $e) { $d->addDay(); if (!self::weekend($d)) $n++; }
        return $n;
    }

    public static function lastWorkingDay(int $y, int $m1): Carbon
    {
        $d = Carbon::create($y, $m1, 1, 0, 0, 0, 'UTC')->endOfMonth()->startOfDay();
        while (self::weekend($d)) $d->subDay();
        return $d;
    }

    public static function deadlines(): array
    {
        $t = Carbon::today('UTC'); $ts = $t->toDateString(); $y = $t->year; $m = $t->month;
        $iva = self::lastWorkingDay($y, $m)->toDateString();
        if ($iva < $ts) $iva = self::lastWorkingDay($m === 12 ? $y + 1 : $y, $m === 12 ? 1 : $m + 1)->toDateString();
        $saft = "$y-04-10"; if ($saft < $ts) $saft = ($y + 1) . '-04-10';
        $inv = "$y-02-15"; if ($inv < $ts) $inv = ($y + 1) . '-02-15';
        $list = [
            ['id' => 'iva', 't' => 'Declaração periódica do IVA (Modelo 7)', 'd' => $iva, 's' => 'Operações do mês anterior · Código do IVA, art. 44.º'],
            ['id' => 'einv', 't' => 'Facturação electrónica obrigatória', 'd' => '2027-01-01', 's' => 'Regimes Geral e Simplificado · Dec. Pres. 71/25'],
            ['id' => 'inv', 't' => 'Ficheiro de inventário (SAF-T)', 'd' => $inv, 's' => 'Até 15 de Fevereiro · art. 24.º'],
            ['id' => 'saft', 't' => 'SAF-T de contabilidade', 'd' => $saft, 's' => 'Até 10 de Abril · art. 24.º'],
        ];
        $list = array_values(array_filter($list, fn ($x) => $x['d'] >= $ts));
        usort($list, fn ($a, $b) => strcmp($a['d'], $b['d']));
        return array_map(fn ($x) => $x + ['days' => (int) round((Carbon::parse($x['d'], 'UTC')->getTimestamp() - $t->getTimestamp()) / 86400)], $list);
    }

    // ---------- encargos por atraso (multa + juros de mora) ----------
    public static function lateCharges(array $o): array
    {
        $fee = (float) ($o['feePct'] ?? 0); $mon = (float) ($o['monthlyPct'] ?? 0); $grace = (int) ($o['graceDays'] ?? 0);
        $today = Carbon::parse($o['today'] ?? now())->startOfDay();
        $days = (int) round(($today->getTimestamp() - Carbon::parse($o['dueDate'])->startOfDay()->getTimestamp()) / 86400);
        $base = max(0, (float) ($o['open'] ?? 0));
        if ($days <= max(0, $grace) || $base <= 0 || (!$fee && !$mon)) return ['days' => max(0, $days), 'base' => $base, 'fee' => 0, 'interest' => 0, 'total' => 0];
        $f = self::r2($base * $fee / 100); $i = self::r2($base * $mon / 100 * $days / 30);
        return ['days' => $days, 'base' => $base, 'fee' => $f, 'interest' => $i, 'total' => self::r2($f + $i)];
    }
}
