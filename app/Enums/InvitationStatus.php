<?php

namespace App\Enums;

enum InvitationStatus: string
{
    case Pending  = 'pending';
    case Accepted = 'accepted';
    case Declined = 'declined';
    case Revoked  = 'revoked';

    public function label(): string
    {
        return match ($this) {
            self::Pending  => 'Invited',
            self::Accepted => 'Accepted',
            self::Declined => 'Declined',
            self::Revoked  => 'Revoked',
        };
    }

    /** Statuses the owner can still revoke (the invitee may have or gain access). */
    public static function active(): array
    {
        return [self::Pending, self::Accepted];
    }
}
