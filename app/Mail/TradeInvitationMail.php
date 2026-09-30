<?php

namespace App\Mail;

use App\Models\TradeInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class TradeInvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly TradeInvitation $invitation) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "{$this->invitation->invitedBy->name} invited you to comment on a trade",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.trade-invitation',
        );
    }
}
