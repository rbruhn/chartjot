<?php

namespace App\Mail;

use App\Models\Friendship;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class FriendRequestMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly Friendship $friendship) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "{$this->friendship->requester->name} sent you a friend request",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.friend-request',
        );
    }
}
