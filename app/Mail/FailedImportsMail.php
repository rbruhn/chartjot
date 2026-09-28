<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use League\Csv\Writer;

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
        $csv = Writer::createFromString();
        $csv->insertOne(['Account Name', 'Trade ID', 'Reason', 'Occurred At']);

        foreach ($this->failures as $f) {
            $csv->insertOne([
                $f['account_name'],
                $f['source_trade_id'] ?? '—',
                $f['reason'],
                $f['occurred_at'],
            ]);
        }

        return [
            Attachment::fromData(fn () => $csv->toString(), 'failed-imports.csv')
                ->withMime('text/csv'),
        ];
    }
}
