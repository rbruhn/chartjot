<?php

namespace App\Models;

use App\Casts\UtcDatetime;
use App\Enums\Direction;
use App\Enums\ExitReason;
use App\Enums\TradeType;
use Database\Factories\TradeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

#[Fillable([
    'uuid', 'journal_id', 'account_id', 'source_trade_id', 'source', 'addon_version',
    'trade_type', 'trade_type_other', 'instrument', 'instrument_symbol',
    'tick_size', 'point_value', 'direction', 'quantity', 'total_entry_quantity',
    'entry_at', 'exit_at', 'entry_price', 'exit_price',
    'entry_order_name', 'exit_order_name', 'exit_reason',
    'points', 'ticks', 'gross_pnl', 'commission', 'fees', 'net_pnl',
    'excursion_mae_points', 'excursion_mfe_points',
    'excursion_max_adverse_price', 'excursion_max_favorable_price',
    'excursion_complete', 'raw_payload',
])]
class Trade extends BaseModel
{
    /** @use HasFactory<TradeFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'trade_type'         => TradeType::class,
            'direction'          => Direction::class,
            'exit_reason'        => ExitReason::class,
            'entry_at'           => UtcDatetime::class,
            'exit_at'            => UtcDatetime::class,
            'excursion_complete' => 'boolean',
            'raw_payload'        => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Trade $trade) {
            $trade->uuid ??= (string) Str::ulid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected function entryAtLocal(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->entry_at?->setTimezone($this->account->effectiveTimezone()),
        );
    }

    protected function exitAtLocal(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->exit_at?->setTimezone($this->account->effectiveTimezone()),
        );
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function executions(): HasMany
    {
        return $this->hasMany(TradeExecution::class);
    }

    public function legs(): HasMany
    {
        return $this->hasMany(TradeLeg::class)->orderBy('sequence');
    }

    public function screenshot(): HasOne
    {
        return $this->hasOne(TradeScreenshot::class)->oldest();
    }

    public function screenshots(): HasMany
    {
        return $this->hasMany(TradeScreenshot::class);
    }

    public function notes(): HasMany
    {
        return $this->hasMany(TradeNote::class)->orderBy('occurred_at');
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(TradeInvitation::class);
    }

    /**
     * The id of the user who owns this trade (via its journal). Always a
     * fresh query, never a loaded relation, so access checks built on it
     * can't be fed a stale or pre-set model.
     */
    public function ownerId(): ?int
    {
        return Journal::whereKey($this->journal_id)->value('user_id');
    }

    public function isOwnedBy(User $user): bool
    {
        return $this->ownerId() === $user->id;
    }
}
