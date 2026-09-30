<?php

namespace App\Http\Controllers;

use App\Enums\InvitationStatus;
use App\Mail\NewTradeCommentMail;
use App\Models\Trade;
use App\Models\TradeComment;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;

class TradeCommentController extends Controller
{
    public function store(Request $request, Trade $trade): RedirectResponse
    {
        // Re-checked on every post: a revoked invitee's stale tab gets a 404.
        abort_unless($request->user()->can('comment', $trade), 404);

        $data = $request->validate([
            'body'              => ['required', 'string', 'max:5000'],
            // One level of nesting: a reply's parent must be a top-level
            // comment on this same trade.
            'parent_comment_id' => [
                'nullable', 'integer',
                Rule::exists('trade_comments', 'id')
                    ->where('trade_id', $trade->id)
                    ->whereNull('parent_comment_id'),
            ],
        ], [
            'parent_comment_id.exists' => 'You can only reply to a top-level comment on this trade.',
        ]);

        $comment = TradeComment::create([
            'trade_id'          => $trade->id,
            'user_id'           => $request->user()->id,
            'parent_comment_id' => $data['parent_comment_id'] ?? null,
            'body'              => trim($data['body']),
        ]);

        $this->notifyParticipants($comment, $trade, $request->user());

        return redirect()->to(route('trades.shared', $trade).'#comment-'.$comment->id);
    }

    /**
     * One email per other participant — the owner plus every invitee who can
     * still view the trade — each addressed individually so no participant
     * sees another's email address.
     */
    private function notifyParticipants(TradeComment $comment, Trade $trade, User $author): void
    {
        $inviteeIds = $trade->invitations()->where('status', InvitationStatus::Accepted)->pluck('invited_user_id');

        User::whereIn('id', $inviteeIds->push($trade->ownerId())->unique())
            ->whereKeyNot($author->id)
            ->get()
            ->filter(fn (User $u) => $u->isActive() && $u->can('viewShared', $trade))
            ->each(fn (User $u) => Mail::to($u->email)->send(
                new NewTradeCommentMail($comment->load(['author', 'trade']))
            ));
    }
}
