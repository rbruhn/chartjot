<?php

use App\Jobs\ImportExecutionsCsv;
use App\Jobs\ImportTradesMaeMfeCsv;
use App\Mail\FailedImportsMail;
use App\Mail\ImportFinishedMail;
use App\Models\Account;
use App\Models\Journal;
use App\Models\Trade;
use App\Models\User;
use App\Services\ExecutionsCsvImporter;
use App\Services\TradesMaeMfeImporter;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;

uses(LazilyRefreshDatabase::class);

/*
 * Issue #46: both CSV import jobs email the journal owner when they finish, with one of three
 * outcomes — succeeded, completed with row-level failures (CSV attached), or failed outright
 * (the importer threw; no attachment, the real reason in the body). The same scenarios run
 * against both importers, using the synthetic NT8 fixtures in tests/Fixtures/ninjatrader
 * (three trades on SimTest1 / SimTest2).
 */

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function notifyJournal(array $accounts = ['SimTest1', 'SimTest2']): Journal
{
    $journal = User::factory()->create()->journal;
    $journal->update(['timezone' => 'America/New_York']);
    foreach ($accounts as $name) {
        Account::factory()->create(['journal_id' => $journal->id, 'name' => $name]);
    }

    return $journal;
}

/** A throwaway copy of a fixture: the jobs delete the file they're given. */
function uploadCopy(string $fixture): string
{
    $path = tempnam(sys_get_temp_dir(), 'upload_');
    copy(base_path("tests/Fixtures/ninjatrader/{$fixture}"), $path);

    return $path;
}

/** Run a job synchronously, the way the queue worker would. */
function runImportJob(string $kind, Journal $journal, string $fixture, string $importId = 'imp'): void
{
    if ($kind === ImportFinishedMail::KIND_EXECUTIONS) {
        (new ImportExecutionsCsv($journal, uploadCopy($fixture), $importId))->handle(app(ExecutionsCsvImporter::class));
    } else {
        (new ImportTradesMaeMfeCsv($journal, uploadCopy($fixture), $importId))->handle(app(TradesMaeMfeImporter::class));
    }
}

function cacheKey(string $kind, string $importId = 'imp'): string
{
    return ($kind === ImportFinishedMail::KIND_EXECUTIONS ? 'csv_import_' : 'mae_mfe_import_').$importId;
}

/** The CSV content of a Mailable's single attachment. */
function attachmentCsv($mail): string
{
    return $mail->attachments()[0]->attachWith(fn () => null, fn ($data) => $data());
}

/** Scenario setup per importer: a journal where the right-file import runs cleanly. */
function cleanSetup(string $kind): Journal
{
    $journal = notifyJournal();
    if ($kind === ImportFinishedMail::KIND_MAE_MFE) {
        app(ExecutionsCsvImporter::class)->import($journal, uploadCopy('Executions.csv'));
    }

    return $journal;
}

/** Scenario setup per importer: a journal where some rows will fail and the rest import. */
function partialSetup(string $kind): Journal
{
    if ($kind === ImportFinishedMail::KIND_EXECUTIONS) {
        return notifyJournal(['SimTest1']);   // SimTest2's trade: unknown account
    }

    $journal = notifyJournal();
    app(ExecutionsCsvImporter::class)->import($journal, uploadCopy('Executions.csv'));
    // SimTest2's trade is gone, so its Trades row has no matching trade.
    Trade::whereHas('account', fn ($q) => $q->where('name', 'SimTest2'))->delete();

    return $journal;
}

$importers = [
    'Executions import' => [ImportFinishedMail::KIND_EXECUTIONS, 'Executions.csv', 'Trades.csv'],
    'Trades (MAE/MFE) import' => [ImportFinishedMail::KIND_MAE_MFE, 'Trades.csv', 'Executions.csv'],
];

// ---------------------------------------------------------------------------
// The three outcomes, for both importers
// ---------------------------------------------------------------------------

test('a clean import sends one success email with no attachment', function (string $kind, string $rightFile) {
    $journal = cleanSetup($kind);
    Mail::fake();

    runImportJob($kind, $journal, $rightFile);

    Mail::assertSent(ImportFinishedMail::class, 1);
    Mail::assertSent(ImportFinishedMail::class, fn (ImportFinishedMail $m) => $m->hasTo($journal->user->email)
        && $m->kind === $kind
        && $m->outcome === ImportFinishedMail::OUTCOME_SUCCEEDED
        && $m->attachments() === []);
    Mail::assertNotSent(FailedImportsMail::class);
    expect(Cache::get(cacheKey($kind))['status'])->toBe('complete');
})->with($importers);

test('an import with row-level failures sends one email with the failures CSV attached', function (string $kind, string $rightFile) {
    $journal = partialSetup($kind);
    Mail::fake();

    runImportJob($kind, $journal, $rightFile);

    // One email for the upload — the importer no longer sends its own failure email as well.
    Mail::assertSent(ImportFinishedMail::class, 1);
    Mail::assertNotSent(FailedImportsMail::class);

    $mail = Mail::sent(ImportFinishedMail::class)->first();
    expect($mail->outcome)->toBe(ImportFinishedMail::OUTCOME_COMPLETED_WITH_FAILURES)
        ->and($mail->attachments())->toHaveCount(1)
        ->and($mail->attachments()[0]->as)->toBe('failed-imports.csv')
        ->and($mail->attachments()[0]->mime)->toBe('text/csv');

    // Same CSV as FailedImportsMail builds for the same failures, one row per failure.
    $csv = attachmentCsv($mail);
    expect($csv)->toBe(attachmentCsv(new FailedImportsMail($journal->name, $mail->failures)))
        ->and($csv)->toStartWith("\"Account Name\",\"Trade ID\",Reason,\"Occurred At\"\n")
        ->and($csv)->toContain('SimTest2')
        ->and(count(array_filter(explode("\n", trim($csv)))) - 1)->toBe(count($mail->failures));

    expect(Cache::get(cacheKey($kind))['status'])->toBe('complete');
})->with($importers);

test('a file that is not the right export fails outright: one email, no attachment, the real reason', function (string $kind, string $rightFile, string $wrongFile) {
    // The maintainer's case: the other NT8 export uploaded to the wrong importer.
    $journal = cleanSetup($kind);
    Mail::fake();

    runImportJob($kind, $journal, $wrongFile);

    Mail::assertSent(ImportFinishedMail::class, 1);
    $mail = Mail::sent(ImportFinishedMail::class)->first();
    $html = $mail->render();

    expect($mail->outcome)->toBe(ImportFinishedMail::OUTCOME_FAILED)
        ->and($mail->attachments())->toBe([])
        ->and($html)->toContain('does not appear to be an NT8')
        ->and($html)->not->toContain('attached');

    expect(Cache::get(cacheKey($kind))['status'])->toBe('failed');
})->with($importers);

test('a mail failure never turns a finished import into a failed one', function (string $kind, string $rightFile) {
    $journal = cleanSetup($kind);
    Mail::shouldReceive('to')->andThrow(new RuntimeException('SMTP down'));

    runImportJob($kind, $journal, $rightFile);

    expect(Cache::get(cacheKey($kind))['status'])->toBe('complete');
})->with($importers);

// ---------------------------------------------------------------------------
// Summaries read naturally for each importer
// ---------------------------------------------------------------------------

test('the Executions summary counts trades imported and duplicates skipped', function () {
    $mail = ImportFinishedMail::forResult(ImportFinishedMail::KIND_EXECUTIONS, 'J', [
        'trades_created' => 143, 'trades_skipped' => 2, 'errors' => [], 'failures' => [],
    ]);

    expect($mail->summary())->toBe('143 trades imported, 2 skipped as duplicates.')
        ->and(ImportFinishedMail::forResult(ImportFinishedMail::KIND_EXECUTIONS, 'J', [
            'trades_created' => 1, 'trades_skipped' => 0, 'errors' => [], 'failures' => [],
        ])->summary())->toBe('1 trade imported.');
});

test('the MAE/MFE summary counts legs across trades and skipped AddOn rows', function () {
    $mail = ImportFinishedMail::forResult(ImportFinishedMail::KIND_MAE_MFE, 'J', [
        'legs_imported' => 156, 'trades_enriched' => 73, 'rows_skipped' => 0, 'errors' => [], 'failures' => [],
    ]);

    expect($mail->summary())->toBe('MAE/MFE added to 73 trades (156 legs).')
        ->and(ImportFinishedMail::forResult(ImportFinishedMail::KIND_MAE_MFE, 'J', [
            'legs_imported' => 1, 'trades_enriched' => 1, 'rows_skipped' => 4, 'errors' => [], 'failures' => [],
        ])->summary())->toBe('MAE/MFE added to 1 trade (1 leg); 4 rows skipped because those trades already have MAE/MFE from the AddOn.');
});

// ---------------------------------------------------------------------------
// No row data in any variant's body (the FailedImportsMailTest rule)
// ---------------------------------------------------------------------------

test('no email variant puts trade or account row data in the body', function (string $kind) {
    $failures = [
        ['account_name' => 'SimTest2', 'source_trade_id' => 'csv_41cf44959b5f9ff1',
         'reason' => "Account 'SimTest2' not found. Create it on the Accounts page before importing.", 'occurred_at' => '2026-10-01 09:00:00'],
    ];
    $counts = $kind === ImportFinishedMail::KIND_EXECUTIONS
        ? ['trades_created' => 2, 'trades_skipped' => 0]
        : ['legs_imported' => 4, 'trades_enriched' => 2, 'rows_skipped' => 0];

    $succeeded = ImportFinishedMail::forResult($kind, 'My Journal', $counts + ['errors' => [], 'failures' => []]);
    $partial   = ImportFinishedMail::forResult($kind, 'My Journal', $counts + ['errors' => [$failures[0]['reason']], 'failures' => $failures]);
    $failed    = ImportFinishedMail::forException($kind, 'My Journal', 'File does not appear to be an NT8 export (missing MAE, MFE).');

    foreach ([$succeeded, $partial, $failed] as $mail) {
        expect($mail->render())->not->toContain('SimTest2')
            ->not->toContain('csv_41cf44959b5f9ff1')
            ->not->toContain("Account 'SimTest2' not found");
    }

    // Each variant is visibly its own thing.
    expect($succeeded->render())->toContain('finished')->not->toContain('attached')
        ->and($partial->render())->toContain('1 row could not be imported')->toContain('CSV of all failures is attached')
        ->and($failed->render())->toContain('failed')->toContain('missing MAE, MFE')->not->toContain('attached');
})->with([ImportFinishedMail::KIND_EXECUTIONS, ImportFinishedMail::KIND_MAE_MFE]);
