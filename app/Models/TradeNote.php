<?php

namespace App\Models;

use App\Casts\UtcDatetime;
use App\Enums\NotePhase;
use Database\Factories\TradeNoteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['trade_id', 'created_by', 'body', 'phase', 'occurred_at'])]
class TradeNote extends BaseModel
{
    /** @use HasFactory<TradeNoteFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'phase'       => NotePhase::class,
            'occurred_at' => UtcDatetime::class,
        ];
    }

    public function trade(): BelongsTo
    {
        return $this->belongsTo(Trade::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
