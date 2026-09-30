<?php

namespace App\Enums;

enum TransactionType: string
{
    case Deposit    = 'deposit';
    case Withdrawal = 'withdrawal';

    public function label(): string
    {
        return match ($this) {
            self::Deposit    => 'Deposit',
            self::Withdrawal => 'Withdrawal',
        };
    }
}
