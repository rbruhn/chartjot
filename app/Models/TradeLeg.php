<?php

namespace App\Models;

use App\Casts\UtcDatetime;
use App\Enums\ExitReason;
use Database\Factories\TradeLegFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'trade_id', 'sequence', 'runner', 'exit_order_id', 'order_name',
    'reason', 'quantity', 'exited_at', 'average_exit_price',
    'points', 'gross_pnl', 'mae_points', 'mfe_points',
])]
class TradeLeg extends BaseModel
{
    /** @use HasFactory<TradeLegFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'runner'    => 'boolean',
            'reason'    => ExitReason::class,
            'exited_at' => UtcDatetime::class,
        ];
    }

    public function trade(): BelongsTo
    {
        return $this->belongsTo(Trade::class);
    }
}
