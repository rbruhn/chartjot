<?php

namespace App\Support;

/** Display formatting shared by the statistics page and its chart components. */
class Format
{
    /** +$1,234.50 / -$80.00 */
    public static function pnl(?float $value): string
    {
        if ($value === null) {
            return '—';
        }

        return ($value >= 0 ? '+' : '-').'$'.number_format(abs($value), 2);
    }

    /** $1,234.50 (unsigned, for balances and costs) */
    public static function money(?float $value): string
    {
        if ($value === null) {
            return '—';
        }

        return ($value < 0 ? '-' : '').'$'.number_format(abs($value), 2);
    }

    /** 12m 30s / 1h 5m, from seconds */
    public static function duration(?float $seconds): string
    {
        if ($seconds === null) {
            return '—';
        }

        $s = (int) round($seconds);
        if ($s < 60) {
            return "{$s}s";
        }
        $m = intdiv($s, 60);
        $s %= 60;
        if ($m < 60) {
            return $s > 0 ? "{$m}m {$s}s" : "{$m}m";
        }
        $h = intdiv($m, 60);
        $m %= 60;

        return $m > 0 ? "{$h}h {$m}m" : "{$h}h";
    }

    public static function pnlClass(?float $value): string
    {
        return ($value ?? 0) >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400';
    }
}
