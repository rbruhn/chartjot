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

    /** Trades from the AddOn or an Executions CSV that couldn't be imported (the original use). */
    public const KIND_TRADES = 'trades';

    /** Trades-export rows that couldn't be matched to a trade to add MAE/MFE to. */
    public const KIND_MAE_MFE = 'mae_mfe';

    /**
     * @param array<array{account_name:string,source_trade_id:string|null,reason:string,occurred_at:string}> $failures
     * @param string $kind  which import failed; only changes the wording, never adds row data to the body
     */
    public function __construct(
        public readonly string $journalName,
        public readonly array  $failures,
        public readonly string $kind = self::KIND_TRADES,
    ) {}

    public function envelope(): Envelope
    {
        $count = count($this->failures);

        return new Envelope(
            subject: $this->kind === self::KIND_MAE_MFE
                ? "MAE/MFE Import — {$count} row".($count === 1 ? '' : 's')." could not be matched to a trade"
                : "Failed Trade Imports — {$count} trade".($count === 1 ? '' : 's')." could not be imported",
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
