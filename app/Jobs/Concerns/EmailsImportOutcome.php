<?php

namespace App\Jobs\Concerns;

use App\Mail\ImportFinishedMail;
use App\Models\Journal;
use Illuminate\Support\Facades\Mail;

/**
 * The completion email both CSV import jobs send: one per upload, whatever the
 * outcome. A mail failure is reported but never rethrown, so it can't change
 * the import status the upload page is polling. Not sent in self-hosted mode.
 */
trait EmailsImportOutcome
{
    protected function emailImportOutcome(Journal $journal, ImportFinishedMail $mail): void
    {
        // Self-hosted mode (issue #102) needs no mail server; the upload page
        // already shows the outcome.
        if (config('chartjot.self_hosted')) {
            return;
        }

        try {
            Mail::to($journal->user()->value('email'))->send($mail);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
