<?php

namespace App\Enums;

enum ExitReason: string
{
    case Stop         = 'stop';
    case ProfitTarget = 'profit_target';
    case Exit         = 'exit';
    case Other        = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Stop         => 'Stop',
            self::ProfitTarget => 'Profit Target',
            self::Exit         => 'Exit',
            self::Other        => 'Other',
        };
    }
}
