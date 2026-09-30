<?php

namespace App\Models;

use App\Enums\InvitationStatus;
use Database\Factories\TradeInvitationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['trade_id', 'invited_user_id', 'invited_by_user_id', 'status'])]
class TradeInvitation extends BaseModel
{
    /** @use HasFactory<TradeInvitationFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => InvitationStatus::class,
        ];
    }

    public function trade(): BelongsTo
    {
        return $this->belongsTo(Trade::class);
    }

    public function invitedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_user_id');
    }

    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_user_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', InvitationStatus::active());
    }

    public function isPending(): bool
    {
        return $this->status === InvitationStatus::Pending;
    }

    public function isAccepted(): bool
    {
        return $this->status === InvitationStatus::Accepted;
    }

    public function isActive(): bool
    {
        return in_array($this->status, InvitationStatus::active(), true);
    }
}
