<?php

namespace App\Services;

use App\Models\Setting;
use Carbon\Carbon;

/** Regras de negócio calculadas a partir dos dados (porta de rules.js). */
class Rules
{
    public const DEFAULTS = ['prop' => '5', 'risk' => '60', 'cob' => '5', 'health' => '75', 'openingCash' => '0'];

    public static function today(): Carbon { return Carbon::today('UTC'); }

    public static function daysBetween($a, $b): int
    {
        return (int) round((Carbon::parse($a)->startOfDay()->getTimestamp() - Carbon::parse($b)->startOfDay()->getTimestamp()) / 86400);
    }

    public static function daysTo($d): int { return self::daysBetween($d, self::today()); }

    public static function settings(): array
    {
        $s = self::DEFAULTS;
        foreach (Setting::all() as $r) $s[$r->key] = $r->value;
        return ['prop' => (int) $s['prop'], 'risk' => (int) $s['risk'], 'cob' => (int) $s['cob'],
            'health' => (int) $s['health'], 'openingCash' => (float) $s['openingCash']];
    }

    public static function setSetting(string $key, $value): void
    {
        Setting::updateOrCreate(['key' => $key], ['value' => (string) $value]);
    }

    public static function companyHealth($c): int
    {
        return (int) round(($c->scoreComercial + $c->scoreFinanceiro + $c->scoreProjectos + $c->scorePessoas + $c->scoreClientes + $c->scoreOperacoes) / 6);
    }

    public static function projectRisk($p): int
    {
        if ($p->completedAt || $p->progress >= 100) return 0;
        $total = max(1, self::daysBetween($p->dueDate, $p->startDate));
        $elapsed = min($total, max(0, self::daysBetween(self::today(), $p->startDate)));
        $gap = max(0, ($elapsed / $total) * 100 - $p->progress);
        $left = self::daysTo($p->dueDate);
        $r = $gap * 1.5 + $p->delayedDeps * 10;
        if ($left < 0) $r = max($r, 90 + min(7, -$left / 5));
        elseif ($left <= 14 && $p->progress < 70) $r += 15;
        return (int) max(0, min(97, round($r)));
    }

    public static function projectStatus($p, int $threshold): string
    {
        if ($p->completedAt || $p->progress >= 100) return 'Concluído';
        if (self::daysTo($p->dueDate) < 0) return 'Atrasado';
        return self::projectRisk($p) >= $threshold ? 'Em risco' : 'No prazo';
    }

    /** Devolve o projecto (modelo) com os campos calculados anexados. */
    public static function withProjectMeta($p, array $cfg)
    {
        $p->setAttribute('risk', self::projectRisk($p));
        $p->setAttribute('status', self::projectStatus($p, $cfg['risk']));
        $p->setAttribute('daysLeft', self::daysTo($p->dueDate));
        return $p;
    }

    public static function processStatus($pr): string
    {
        return $pr->execution >= 85 ? 'No prazo' : ($pr->execution >= 70 ? 'Em risco' : 'Atrasado');
    }

    public static function invoiceView($i)
    {
        $d = self::daysTo($i->dueDate);
        $i->setAttribute('daysToDue', $d);
        $i->setAttribute('overdueDays', $d < 0 ? -$d : 0);
        $i->setAttribute('status', $i->paidAt ? 'Paga' : ($d < 0 ? 'Vencida' : 'A vencer'));
        return $i;
    }
}
