<?php

namespace App\Models;

use App\Enums\FriendshipStatus;
use Database\Factories\FriendshipFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

#[Fillable(['requester_id', 'recipient_id', 'status'])]
class Friendship extends BaseModel
{
    /** @use HasFactory<FriendshipFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => FriendshipStatus::class,
        ];
    }

    protected static function booted(): void
    {
        // Keep the normalized pair (backing the unordered unique index) in
        // sync with requester/recipient, whichever direction was saved.
        static::saving(function (Friendship $friendship) {
            if ((int) $friendship->requester_id === (int) $friendship->recipient_id) {
                throw new InvalidArgumentException('A user cannot befriend themselves.');
            }

            $friendship->user_low_id  = min($friendship->requester_id, $friendship->recipient_id);
            $friendship->user_high_id = max($friendship->requester_id, $friendship->recipient_id);
        });
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_id');
    }

    /** The friendship row for this pair of users, in either direction. */
    public function scopeBetween(Builder $query, User|int $a, User|int $b): Builder
    {
        $a = $a instanceof User ? $a->id : $a;
        $b = $b instanceof User ? $b->id : $b;

        return $query->where('user_low_id', min($a, $b))->where('user_high_id', max($a, $b));
    }

    public function scopeInvolving(Builder $query, User $user): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->where('requester_id', $user->id)
            ->orWhere('recipient_id', $user->id));
    }

    public function scopeAccepted(Builder $query): Builder
    {
        return $query->where('status', FriendshipStatus::Accepted);
    }

    public function isAccepted(): bool
    {
        return $this->status === FriendshipStatus::Accepted;
    }

    public function isPending(): bool
    {
        return $this->status === FriendshipStatus::Pending;
    }

    /** The user on the other side of this friendship from $user. */
    public function otherUser(User $user): User
    {
        return (int) $this->requester_id === (int) $user->id ? $this->recipient : $this->requester;
    }
}
