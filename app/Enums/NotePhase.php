<?php

namespace App\Enums;

enum NotePhase: string
{
    case PreTrade  = 'pre_trade';
    case InTrade   = 'in_trade';
    case PostTrade = 'post_trade';
    case General   = 'general';

    public function label(): string
    {
        return match ($this) {
            self::PreTrade  => 'Pre-Trade',
            self::InTrade   => 'In-Trade',
            self::PostTrade => 'Post-Trade',
            self::General   => 'General',
        };
    }
}
