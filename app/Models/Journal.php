<?php

namespace App\Models;

use Database\Factories\JournalFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Trade;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

#[Fillable(['name', 'timezone'])]
#[Hidden(['ingest_token_hash'])]
class Journal extends BaseModel
{
    /** @use HasFactory<JournalFactory> */
    use HasFactory;

    /**
     * Create a journal and return its one-time intake token.
     */
    public static function createForUser(User $user): string
    {
        $token = Str::random(64);

        $journal = new static;

        $journal->forceFill([
            'name' => "{$user->name}'s Trade Journal",
            'timezone' => null,
            'ingest_token_hash' => Hash::make($token),
        ]);
        $journal->user()->associate($user);
        $journal->save();

        return $token;
    }

    /**
     * Replace the intake token and return its one-time plaintext value.
     */
    public function rotateIngestToken(): string
    {
        $token = Str::random(64);

        $this->forceFill([
            'ingest_token_hash' => Hash::make($token),
        ])->save();

        return $token;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class);
    }

    public function trades(): HasMany
    {
        return $this->hasMany(Trade::class);
    }
}
