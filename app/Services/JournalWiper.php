<?php

namespace App\Services;

use App\Models\AccountTransaction;
use App\Models\Journal;
use App\Models\TradeComment;
use App\Models\TradeExecution;
use App\Models\TradeInvitation;
use App\Models\TradeLeg;
use App\Models\TradeNote;
use App\Models\TradeScreenshot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Removes every account in a journal with all its trades and deposits/withdrawals, and the image files (#108).
 * Used by Delete All Accounts and by demo:install (#115). The journal, its settings and intake token stay.
 *
 * Each table is deleted explicitly, children first, rather than left to cascadeOnDelete: the query cache
 * (lada-cache) only invalidates tables it sees written, so rows the database cascades away would still be
 * served from the cache (trades whose account is gone).
 */
class JournalWiper
{
    public function wipe(Journal $journal): void
    {
        $tradeIds   = $journal->trades()->select('id');
        $accountIds = $journal->accounts()->select('id');

        $files = TradeScreenshot::whereIn('trade_id', $tradeIds)->get(['disk', 'path'])
            ->map(fn (TradeScreenshot $shot) => [$shot->disk, $shot->path])
            ->concat(TradeComment::whereIn('trade_id', $tradeIds)->whereNotNull('image_path')->get(['image_disk', 'image_path'])
                ->map(fn (TradeComment $comment) => [$comment->image_disk, $comment->image_path]));

        DB::transaction(function () use ($journal, $tradeIds, $accountIds) {
            foreach ([TradeExecution::class, TradeLeg::class, TradeNote::class, TradeScreenshot::class, TradeInvitation::class] as $model) {
                $model::whereIn('trade_id', $tradeIds)->delete();
            }
            // Replies first: they point at their parent comment.
            TradeComment::whereIn('trade_id', $tradeIds)->whereNotNull('parent_comment_id')->delete();
            TradeComment::whereIn('trade_id', $tradeIds)->delete();
            // Followers' master links are cleared first so no trade points at one being deleted.
            $journal->trades()->whereNotNull('master_trade_id')->update(['master_trade_id' => null]);
            $journal->trades()->delete();
            AccountTransaction::whereIn('account_id', $accountIds)->delete();
            $journal->accounts()->delete();
        });

        // Only once the rows are gone, so a refused or failed wipe never loses images.
        foreach ($files as [$disk, $path]) {
            Storage::disk($disk)->delete($path);
        }
    }
}
