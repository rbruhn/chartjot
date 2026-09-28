<?php

namespace App\Models;

use App\Casts\UtcDatetime;
use App\Enums\CopyStatus;
use App\Enums\Direction;
use Database\Factories\TradeCopyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'trade_id', 'account_id', 'status',
    'instrument_contract', 'instrument_symbol',
    'instrument_tick_size', 'instrument_point_value',
    'expected_contract_size', 'expected_multiplier',
    'expected_faded', 'expected_blown', 'expected_quantity',
    'warnings', 'direction', 'quantity',
    'entry_average_price', 'exit_average_price',
    'entered_at', 'exited_at',
    'points', 'ticks', 'gross_pnl', 'commission', 'fees', 'net_pnl',
])]
class TradeCopy extends BaseModel
{
    /** @use HasFactory<TradeCopyFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status'          => CopyStatus::class,
            'direction'       => Direction::class,
            'expected_faded'  => 'boolean',
            'expected_blown'  => 'boolean',
            'warnings'        => 'array',
            'entered_at'      => UtcDatetime::class,
            'exited_at'       => UtcDatetime::class,
        ];
    }

    public function trade(): BelongsTo
    {
        return $this->belongsTo(Trade::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function executions(): HasMany
    {
        return $this->hasMany(TradeCopyExecution::class);
    }
}
