<?php

namespace App\Policies;

use App\Enums\InvitationStatus;
use App\Models\Trade;
use App\Models\TradeInvitation;
use App\Models\User;

/**
 * Cross-user access to a single trade (issue #18). Everything here is
 * evaluated from the database on every call — nothing is cached in the
 * session — so revoking an invitation or ending a friendship cuts access
 * off on the very next request.
 */
class TradePolicy
{
    /**
     * Invite friends to this trade, or revoke their invitations: owner only.
     * Never in self-hosted mode (issue #102), which has no friends.
     */
    public function invite(User $user, Trade $trade): bool
    {
        return ! config('chartjot.self_hosted') && $trade->isOwnedBy($user);
    }

    /**
     * View the standalone shared-trade page: the owner, or a user whose
     * invitation to THIS trade is accepted and who is still the owner's
     * friend. A pending, declined or revoked invitation grants nothing.
     */
    public function viewShared(User $user, Trade $trade): bool
    {
        $ownerId = $trade->ownerId();

        if ($ownerId === $user->id) {
            return true;
        }

        $accepted = TradeInvitation::where('trade_id', $trade->id)
            ->where('invited_user_id', $user->id)
            ->where('status', InvitationStatus::Accepted)
            ->exists();

        return $accepted
            && $ownerId !== null
            && $user->isFriendsWith(User::findOrFail($ownerId));
    }

    /** Post to the trade's comment thread: exactly the people who can view it. */
    public function comment(User $user, Trade $trade): bool
    {
        return $this->viewShared($user, $trade);
    }
}
