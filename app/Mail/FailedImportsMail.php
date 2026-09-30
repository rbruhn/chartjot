<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Trades the AddOn sent that couldn't be imported. CSV uploads report through ImportFinishedMail. */
class FailedImportsMail extends Mailable
{
    use Queueable, SerializesModels;

    /** @param array<array{account_name:string,source_trade_id:string|null,reason:string,occurred_at:string}> $failures */
    public function __construct(
        public readonly string $journalName,
        public readonly array  $failures,
    ) {}

    public function envelope(): Envelope
    {
        $count = count($this->failures);

        return new Envelope(
            subject: "Failed Trade Imports — {$count} trade".($count === 1 ? '' : 's')." could not be imported",
        );
    }

    public function content(): Content
    {
        return new Content(view: 'mail.failed-imports');
    }

    /** @return array<Attachment> */
    public function attachments(): array
    {
        return [FailuresCsv::attachment($this->failures)];
    }
}
