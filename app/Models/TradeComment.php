<?php

namespace App\Models;

use Database\Factories\TradeCommentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['trade_id', 'user_id', 'parent_comment_id', 'body', 'image_disk', 'image_path', 'image_mime_type'])]
class TradeComment extends BaseModel
{
    /** @use HasFactory<TradeCommentFactory> */
    use HasFactory;

    public function trade(): BelongsTo
    {
        return $this->belongsTo(Trade::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(TradeComment::class, 'parent_comment_id');
    }

    public function hasImage(): bool
    {
        return $this->image_path !== null;
    }

    public function replies(): HasMany
    {
        return $this->hasMany(TradeComment::class, 'parent_comment_id')->oldest();
    }
}
