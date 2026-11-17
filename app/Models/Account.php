<?php

namespace App\Models;

use App\Enums\AccountType;
use Database\Factories\AccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['journal_id', 'name', 'connection', 'timezone', 'account_type', 'starting_balance'])]
class Account extends BaseModel
{
    /** @use HasFactory<AccountFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'account_type' => AccountType::class,
            'starting_balance' => 'decimal:2',
        ];
    }

    /**
     * Finds the journal's account by name, creating it when a trade arrives (from the AddOn or a CSV import)
     * for an account the journal has not seen. The trader can correct its type, balance and timezone on the
     * Accounts page afterwards.
     */
    public static function resolveForJournal(Journal $journal, string $name, ?string $connection): self
    {
        $account = self::firstOrCreate(
            ['journal_id' => $journal->id, 'name' => $name],
            [
                'connection' => $connection,
                'account_type' => preg_match('/^(sim|playback)/i', $name) ? AccountType::Sim : AccountType::Funded,
            ]
        );

        // Fill in the connection later if the account was created without one.
        if ($account->connection === null && $connection !== null) {
            $account->update(['connection' => $connection]);
        }

        return $account;
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class);
    }

    public function trades(): HasMany
    {
        return $this->hasMany(Trade::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(AccountTransaction::class);
    }

    public function effectiveTimezone(): string
    {
        return $this->timezone ?? $this->journal->timezone ?? 'UTC';
    }
}
