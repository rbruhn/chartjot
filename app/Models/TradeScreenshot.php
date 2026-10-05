<?php

namespace App\Models;

use App\Casts\UtcDatetime;
use App\Enums\ScreenshotKind;
use App\Enums\ScreenshotSource;
use Database\Factories\TradeScreenshotFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'trade_id', 'kind', 'disk', 'path', 'caption',
    'mime_type', 'bytes', 'captured_at', 'source',
])]
class TradeScreenshot extends BaseModel
{
    /** @use HasFactory<TradeScreenshotFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'kind'        => ScreenshotKind::class,

            'source'      => ScreenshotSource::class,
            'captured_at' => UtcDatetime::class,
        ];
    }

    public function trade(): BelongsTo
    {
        return $this->belongsTo(Trade::class);
    }
}
