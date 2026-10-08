<?php

namespace App\Models;

use App\Enums\UserStatus;
use App\Observers\UserObserver;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spiritix\LadaCache\Database\LadaCacheTrait;

#[ObservedBy([UserObserver::class])]
#[Fillable(['name', 'email', 'password', 'status', 'is_admin', 'is_demo'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, LadaCacheTrait, Notifiable;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password'          => 'hashed',
            'status'            => UserStatus::class,
            'is_admin'          => 'boolean',
            'is_demo'           => 'boolean',
        ];
    }

    public function journal(): HasOne
    {
        return $this->hasOne(Journal::class);
    }

    public function isAdmin(): bool
    {
        return (bool) $this->is_admin;
    }

    /** #115: the read-only demo account. */
    public function isDemo(): bool
    {
        return (bool) $this->is_demo;
    }

    public function isActive(): bool
    {
        return $this->status === UserStatus::Active;
    }

    public function isPending(): bool
    {
        return $this->status === UserStatus::Pending;
    }

    /** Whether an accepted friendship exists between this user and $other, in either direction. */
    public function isFriendsWith(User $other): bool
    {
        return $this->isNot($other)
            && Friendship::between($this, $other)->accepted()->exists();
    }
}
