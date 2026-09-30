<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/**
 * Sent when a queued CSV import finishes — for both importers, one email per
 * upload — with one of three outcomes:
 *
 * - succeeded: every row imported. A summary, no attachment.
 * - completed with failures: the importer ran, but some rows failed (each
 *   recorded as a FailedTradeImport). The summary, plus those failures as
 *   the same CSV FailedImportsMail attaches.
 * - failed: the importer threw before importing anything (e.g. the wrong
 *   export was uploaded). The reason, no attachment — there are no rows.
 *
 * Failure details only ever travel in the attachment; the body carries counts
 * and guidance, plus the whole-file reason for an outright failure.
 */
class ImportFinishedMail extends Mailable
{
    use Queueable, SerializesModels;

    /** NT8 Trade Performance → Executions upload (ExecutionsCsvImporter). */
    public const KIND_EXECUTIONS = 'executions';

    /** NT8 Trade Performance → Trades upload, leg-level MAE/MFE (TradesMaeMfeImporter). */
    public const KIND_MAE_MFE = 'mae_mfe';

    public const OUTCOME_SUCCEEDED = 'succeeded';
    public const OUTCOME_COMPLETED_WITH_FAILURES = 'completed_with_failures';
    public const OUTCOME_FAILED = 'failed';

    /**
     * @param array $result  the importer's result (counts, errors, failures); empty when it failed
     * @param array<array{account_name:string,source_trade_id:string|null,reason:string,occurred_at:string}> $failures
     */
    public function __construct(
        public readonly string  $kind,
        public readonly string  $outcome,
        public readonly string  $journalName,
        public readonly array   $result = [],
        public readonly array   $failures = [],
        public readonly ?string $failureReason = null,
    ) {}

    /** The importer ran: succeeded, or completed with row-level failures. */
    public static function forResult(string $kind, string $journalName, array $result): self
    {
        $failures = $result['failures'] ?? [];

        return new self(
            kind: $kind,
            outcome: $failures ? self::OUTCOME_COMPLETED_WITH_FAILURES : self::OUTCOME_SUCCEEDED,
            journalName: $journalName,
            result: $result,
            failures: $failures,
        );
    }

    /** The importer threw before importing anything. */
    public static function forException(string $kind, string $journalName, string $reason): self
    {
        return new self(
            kind: $kind,
            outcome: self::OUTCOME_FAILED,
            journalName: $journalName,
            failureReason: $reason,
        );
    }

    /** "Executions import" / "MAE/MFE import" */
    public function importName(): string
    {
        return $this->kind === self::KIND_MAE_MFE ? 'MAE/MFE import' : 'Executions import';
    }

    /**
     * What the import did, in each importer's own terms. The two result shapes
     * differ, so each gets its own sentence:
     *   Executions: "143 trades imported, 2 skipped as duplicates."
     *   MAE/MFE:    "MAE/MFE added to 73 trades (156 legs)."
     */
    public function summary(): string
    {
        $r = $this->result;

        if ($this->kind === self::KIND_MAE_MFE) {
            $trades = (int) ($r['trades_enriched'] ?? 0);
            $legs   = (int) ($r['legs_imported'] ?? 0);
            $skip   = (int) ($r['rows_skipped'] ?? 0);

            return "MAE/MFE added to {$trades} ".Str::plural('trade', $trades)." ({$legs} ".Str::plural('leg', $legs).')'
                .($skip > 0 ? "; {$skip} ".Str::plural('row', $skip).' skipped because those trades already have MAE/MFE from the AddOn' : '')
                .'.';
        }

        $created = (int) ($r['trades_created'] ?? 0);
        $skipped = (int) ($r['trades_skipped'] ?? 0);

        return "{$created} ".Str::plural('trade', $created).' imported'
            .($skipped > 0 ? ", {$skipped} skipped as duplicates" : '')
            .'.';
    }

    public function envelope(): Envelope
    {
        $count = count($this->failures);

        return new Envelope(subject: match ($this->outcome) {
            self::OUTCOME_SUCCEEDED               => ucfirst($this->importName()).' finished',
            self::OUTCOME_COMPLETED_WITH_FAILURES => ucfirst($this->importName())." finished — {$count} ".Str::plural('row', $count).' could not be imported',
            default                               => ucfirst($this->importName()).' failed',
        });
    }

    public function content(): Content
    {
        return new Content(view: 'mail.import-finished', with: [
            'importName' => ucfirst($this->importName()),
            'summary'    => $this->summary(),
        ]);
    }

    /** @return array<Attachment> */
    public function attachments(): array
    {
        return $this->outcome === self::OUTCOME_COMPLETED_WITH_FAILURES
            ? [FailuresCsv::attachment($this->failures)]
            : [];
    }
}
