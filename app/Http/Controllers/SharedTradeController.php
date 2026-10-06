<?php

namespace App\Http\Controllers;

use App\Models\Trade;
use App\Models\TradeComment;
use App\Models\TradeNote;
use App\Models\TradeScreenshot;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The standalone shared-trade page (issue #18). Its own bare layout, no app
 * navigation, and the view receives only plain arrays of the fields an
 * invited friend may see — never the Trade model — so nothing reachable
 * from a model (account, journal, other trades) can end up in the page.
 *
 * Unauthorized is a 404, like TradeNoteController and the owner's
 * screenshot route: a stranger can't even confirm the trade exists.
 */
class SharedTradeController extends Controller
{
    public function show(Request $request, Trade $trade): View
    {
        abort_unless($request->user()->can('viewShared', $trade), 404);

        $entryLocal = $trade->entry_at_local;

        return view('shared.trade', [
            'trade' => [
                'uuid'        => $trade->uuid,
                'title'       => ucfirst($trade->direction->value).' '.$trade->quantity.' '.$trade->instrument,
                'instrument'  => $trade->instrument,
                'direction'   => $trade->direction->value,
                'quantity'    => $trade->quantity,
                'entry_price' => (float) $trade->entry_price,
                'exit_price'  => (float) $trade->exit_price,
                'points'      => (float) $trade->points,
                'net_pnl'     => (float) $trade->net_pnl,
                'date'        => $entryLocal->format('D M j, Y'),
                'owner_name'  => User::whereKey($trade->ownerId())->value('name'),
            ],
            // Named links, each opening its image in a modal (#91); none is shown inline.
            'images' => $trade->chartImages()
                ->map(fn (array $image) => [
                    'id'    => $image['screenshot']->id,
                    'label' => $image['label'],
                    'url'   => route('trades.shared.screenshot', [$trade, $image['screenshot']]),
                ])->all(),
            'notes' => $trade->notes()->get()
                ->map(fn (TradeNote $n) => [
                    'phase' => $n->phase->label(),
                    'body'  => $n->body,
                ])->all(),
            'comments' => $trade->comments()
                ->whereNull('parent_comment_id')
                ->with(['author', 'replies.author'])
                ->oldest()
                ->get()
                ->map(fn (TradeComment $c) => [
                    ...$this->comment($c, $trade),
                    'replies' => $c->replies->map(fn (TradeComment $r) => $this->comment($r, $trade))->all(),
                ])->all(),
        ]);
    }

    public function screenshot(Request $request, Trade $trade, TradeScreenshot $screenshot): StreamedResponse
    {
        abort_unless($request->user()->can('viewShared', $trade), 404);
        abort_if($screenshot->trade_id !== $trade->id, 404);
        abort_unless(Storage::disk($screenshot->disk)->exists($screenshot->path), 404);

        return Storage::disk($screenshot->disk)->response($screenshot->path, null, [
            'Content-Type' => $screenshot->mime_type,
        ]);
    }

    /** An image attached to a comment on this trade, for anyone who can view the trade. */
    public function commentImage(Request $request, Trade $trade, TradeComment $comment): StreamedResponse
    {
        abort_unless($request->user()->can('viewShared', $trade), 404);
        abort_if($comment->trade_id !== $trade->id || ! $comment->hasImage(), 404);
        abort_unless(Storage::disk($comment->image_disk)->exists($comment->image_path), 404);

        return Storage::disk($comment->image_disk)->response($comment->image_path, null, [
            'Content-Type'           => $comment->image_mime_type,
            'X-Content-Type-Options' => 'nosniff',
        ], 'inline');
    }

    private function comment(TradeComment $comment, Trade $trade): array
    {
        return [
            'id'        => $comment->id,
            'author'    => $comment->author->name,
            'body'      => $comment->body,
            'when'      => $comment->created_at->diffForHumans(),
            'image_url' => $comment->hasImage() ? route('trades.shared.comment-image', [$trade, $comment]) : null,
        ];
    }
}
