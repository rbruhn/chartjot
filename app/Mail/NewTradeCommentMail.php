<?php

namespace App\Mail;

use App\Models\TradeComment;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class NewTradeCommentMail extends Mailable
{
    use Queueable, SerializesModels;

    /** @param string $url  where this recipient reads the thread (journal for the owner, conversation page for invitees) */
    public function __construct(
        public readonly TradeComment $comment,
        public readonly string $url,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "{$this->comment->author->name} commented on a {$this->comment->trade->instrument} trade",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.new-trade-comment',
        );
    }
}
