<?php

namespace App\Enums;

enum TradeType: string
{
    case SecondEntryShort       = '2ES';
    case SecondEntryLong        = '2EL';
    case RangeShort             = 'RS';
    case RangeLong              = 'RL';
    case FailedSecondEntryShort = 'F2ES';
    case FailedSecondEntryLong  = 'F2EL';
    case Other                  = 'Other';

    public function label(): string
    {
        return match ($this) {
            self::SecondEntryShort       => 'Second Entry Short',
            self::SecondEntryLong        => 'Second Entry Long',
            self::RangeShort             => 'Range Short',
            self::RangeLong              => 'Range Long',
            self::FailedSecondEntryShort => 'Failed Second Entry Short',
            self::FailedSecondEntryLong  => 'Failed Second Entry Long',
            self::Other                  => 'Other',
        };
    }

    public function impliedDirection(): ?Direction
    {
        return match ($this) {
            self::SecondEntryShort, self::RangeShort => Direction::Short,
            self::SecondEntryLong, self::RangeLong   => Direction::Long,
            self::FailedSecondEntryShort             => Direction::Long,
            self::FailedSecondEntryLong              => Direction::Short,
            self::Other                              => null,
        };
    }
}
