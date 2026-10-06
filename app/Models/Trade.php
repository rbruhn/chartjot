<?php

namespace App\Models;

use App\Casts\UtcDatetime;
use App\Enums\Direction;
use App\Enums\ExitReason;
use App\Enums\ScreenshotKind;
use App\Enums\ScreenshotSource;
use App\Enums\TradeType;
use Database\Factories\TradeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

#[Fillable([
    'uuid', 'journal_id', 'account_id', 'source_trade_id', 'copier_master_source_trade_id', 'source', 'addon_version',
    'trade_type', 'trade_type_other', 'instrument', 'instrument_symbol',
    'tick_size', 'point_value', 'direction', 'quantity', 'total_entry_quantity',
    'entry_at', 'exit_at', 'entry_price', 'exit_price', 'stop_price',
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

    /** The trade's main chart image: the first exit image (never the entry image, which is captured earlier). */
    public function screenshot(): HasOne
    {
        return $this->hasOne(TradeScreenshot::class)->where('kind', ScreenshotKind::Exit)->oldest();
    }

    /** Every image of the trade, entry included (for cleanup). */
    public function screenshots(): HasMany
    {
        return $this->hasMany(TradeScreenshot::class);
    }

    /**
     * The trade's chart images as named links (#91), in display order: the entry image, the AddOn's exit image,
     * then the trader's uploads oldest first. An upload is labelled by the name the trader gave it (its caption);
     * unnamed uploads are "Image 1", "Image 2", ... Uses the loaded `screenshots` relation when present.
     *
     * @return Collection<int, array{screenshot: TradeScreenshot, label: string}>
     */
    public function chartImages(): Collection
    {
        $shots = $this->screenshots->sortBy('id')->values();

        $entry   = $shots->filter(fn (TradeScreenshot $s) => $s->kind === ScreenshotKind::Entry);
        $uploads = $shots->filter(fn (TradeScreenshot $s) => $s->kind === ScreenshotKind::Exit && $s->source === ScreenshotSource::ManualUpload);
        $exit    = $shots->filter(fn (TradeScreenshot $s) => $s->kind === ScreenshotKind::Exit && $s->source !== ScreenshotSource::ManualUpload);

        $numbered = fn (string $label, int $i) => $i === 0 ? $label : $label.' '.($i + 1);
        $unnamed  = 0;

        return collect()
            ->concat($entry->values()->map(fn ($s, $i) => ['screenshot' => $s, 'label' => $numbered('Entry Image', $i)]))
            ->concat($exit->values()->map(fn ($s, $i) => ['screenshot' => $s, 'label' => $numbered('Exit Image', $i)]))
            ->concat($uploads->values()->map(function (TradeScreenshot $s) use (&$unnamed) {
                $name = trim((string) $s->caption);

                return ['screenshot' => $s, 'label' => $name !== '' ? $name : 'Image '.(++$unnamed)];
            }))
            ->values();
    }

    /** The image captured when the trade opened (#72), shown only through the "Entry Image" link. */
    public function entryScreenshot(): HasOne
    {
        return $this->hasOne(TradeScreenshot::class)->where('kind', ScreenshotKind::Entry)->latest('id');
    }

    /** #79: the copier master this follower copied, in the same journal. Null for a master or a normal trade. */
    public function master(): BelongsTo
    {
        return $this->belongsTo(Trade::class, 'master_trade_id');
    }

    /** #79: the copier followers of this master. A trade with followers is a master. */
    public function followers(): HasMany
    {
        return $this->hasMany(Trade::class, 'master_trade_id')->orderBy('entry_at');
    }

    /**
     * #79: the journal list's rows. A follower is grouped under its master, so with no account selection the rows
     * are masters and normal trades. With a selection, every selected trade except a follower whose master is also
     * selected, so filtering to a follower account shows its own trades as rows. Totals are not limited by this:
     * they add every trade of the selected accounts.
     *
     * @param  array<int>|null  $accountIds
     */
    public function scopeListedRows($query, ?array $accountIds = null): void
    {
        if ($accountIds === null) {
            $query->whereNull('master_trade_id');

            return;
        }

        $query->where(fn ($q) => $q->whereNull('master_trade_id')
            ->orWhereHas('master', fn ($m) => $m->whereNotIn('account_id', $accountIds)));
    }

    public function notes(): HasMany
    {
        return $this->hasMany(TradeNote::class)->orderBy('occurred_at');
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(TradeInvitation::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(TradeComment::class);
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
