<?php

namespace App\Enums;

enum FriendshipStatus: string
{
    case Pending  = 'pending';
    case Accepted = 'accepted';
    case Declined = 'declined';

    public function label(): string
    {
        return match ($this) {
            self::Pending  => 'Pending',
            self::Accepted => 'Friends',
            self::Declined => 'Declined',
        };
    }
}
