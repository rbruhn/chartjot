<?php

namespace App\Mail;

use Illuminate\Mail\Mailables\Attachment;
use League\Csv\Writer;

/**
 * The failures CSV attached to import emails (FailedImportsMail for AddOn
 * intake, ImportFinishedMail for CSV uploads): one row per failure. Failure
 * details only ever travel in this attachment, never in an email body.
 */
class FailuresCsv
{
    /** @param array<array{account_name:string,source_trade_id:string|null,reason:string,occurred_at:string}> $failures */
    public static function attachment(array $failures): Attachment
    {
        return Attachment::fromData(fn () => self::contents($failures), 'failed-imports.csv')
            ->withMime('text/csv');
    }

    public static function contents(array $failures): string
    {
        $csv = Writer::createFromString();
        $csv->insertOne(['Account Name', 'Trade ID', 'Reason', 'Occurred At']);

        foreach ($failures as $f) {
            $csv->insertOne([
                $f['account_name'],
                $f['source_trade_id'] ?? '—',
                $f['reason'],
                $f['occurred_at'],
            ]);
        }

        return $csv->toString();
    }
}
