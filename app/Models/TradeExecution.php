<?php

namespace App\Models;

use App\Casts\UtcDatetime;
use App\Enums\ExecutionAction;
use App\Enums\ExecutionRole;
use Database\Factories\TradeExecutionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'trade_id', 'source_execution_id', 'order_id', 'occurred_at',
    'action', 'role', 'quantity', 'allocated_quantity',
    'price', 'commission', 'fee', 'order_name', 'position_after',
])]
class TradeExecution extends BaseModel
{
    /** @use HasFactory<TradeExecutionFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'action'      => ExecutionAction::class,
            'role'        => ExecutionRole::class,
            'occurred_at' => UtcDatetime::class,
        ];
    }

    public function trade(): BelongsTo
    {
        return $this->belongsTo(Trade::class);
    }
}
