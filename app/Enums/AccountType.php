<?php

namespace App\Enums;

enum AccountType: string
{
    case Sim    = 'sim';
    case Eval   = 'eval';
    case Funded = 'funded';

    public function label(): string
    {
        return match ($this) {
            self::Sim    => 'Sim',
            self::Eval   => 'Eval',
            self::Funded => 'Funded',
        };
    }
}
