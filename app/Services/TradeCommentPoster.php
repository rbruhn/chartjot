<?php

namespace App\Services;

use App\Support\Demo;
use App\Enums\InvitationStatus;
use App\Mail\NewTradeCommentMail;
use App\Models\Trade;
use App\Models\TradeComment;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The one place a trade comment is created, whether it comes from the
 * shared conversation page (an invitee or the owner) or from the owner's
 * journal trade page — so authorization, the nesting rule, rate limiting
 * and notifications can't drift apart between the two.
 */
class TradeCommentPoster
{
    /** Each comment emails every other participant, so cap how fast anyone posts. */
    public const PER_MINUTE = 20;

    /**
     * @param  array{body?: mixed, parent_comment_id?: mixed, image?: mixed}  $input
     */
    public function post(Trade $trade, User $author, array $input): TradeComment
    {
        // Re-checked on every post: a revoked invitee gets a 404.
        abort_unless($author->can('comment', $trade), 404);
        Demo::ensureWritable(); // #115: before an image is stored

        $data = Validator::make($input, [
            'body'              => ['required', 'string', 'max:5000'],
            // One level of nesting: a reply's parent must be a top-level
            // comment on this same trade.
            'parent_comment_id' => [
                'nullable', 'integer',
                Rule::exists('trade_comments', 'id')
                    ->where('trade_id', $trade->id)
                    ->whereNull('parent_comment_id'),
            ],
            // Same rule as trade screenshots: raster images only (no SVG,
            // which could carry script), 10 MB max.
            'image'             => ['nullable', 'file', 'mimes:png,jpeg,jpg', 'max:10240'],
        ], [
            'parent_comment_id.exists' => 'You can only reply to a top-level comment on this trade.',
        ])->validate();

        $key = 'trade-comment:'.$author->id;
        if (RateLimiter::tooManyAttempts($key, self::PER_MINUTE)) {
            throw ValidationException::withMessages([
                'body' => 'You are commenting too quickly. Please wait a minute and try again.',
            ]);
        }
        RateLimiter::hit($key, 60);

        $image = $this->storeImage($trade, $data['image'] ?? null);

        $comment = TradeComment::create([
            'trade_id'          => $trade->id,
            'user_id'           => $author->id,
            'parent_comment_id' => $data['parent_comment_id'] ?? null,
            'body'              => trim($data['body']),
            ...$image,
        ]);

        $this->notifyParticipants($comment, $trade, $author);

        return $comment;
    }

    /**
     * Store an attached image on the private disk under a server-generated
     * name (the client's filename is never used) and return the columns to
     * save. The MIME type is the one detected from the file's contents.
     */
    private function storeImage(Trade $trade, mixed $file): array
    {
        if (! $file) {
            return [];
        }

        $name = Str::ulid().'.'.$file->extension();
        $path = Storage::disk('local')->putFileAs("trade-comment-images/{$trade->id}", $file, $name);

        return [
            'image_disk'      => 'local',
            'image_path'      => $path,
            'image_mime_type' => $file->getMimeType(),
        ];
    }

    /**
     * One email per other participant — the owner plus every invitee who can
     * still view the trade — each addressed individually so no participant
     * sees another's address. The owner's link opens the trade in their
     * journal; an invitee's opens the conversation page.
     */
    private function notifyParticipants(TradeComment $comment, Trade $trade, User $author): void
    {
        $ownerId    = $trade->ownerId();
        $inviteeIds = $trade->invitations()->where('status', InvitationStatus::Accepted)->pluck('invited_user_id');
        $comment->load(['author', 'trade']);

        User::whereIn('id', $inviteeIds->push($ownerId)->unique())
            ->whereKeyNot($author->id)
            ->get()
            ->filter(fn (User $u) => $u->isActive() && $u->can('viewShared', $trade))
            ->each(function (User $u) use ($comment, $trade, $ownerId) {
                $url = $u->id === $ownerId
                    ? route('journal.index', ['trade' => $trade->uuid])
                    : route('trades.shared', $trade).'#comment-'.$comment->id;

                Mail::to($u->email)->send(new NewTradeCommentMail($comment, $url));
            });
    }
}
