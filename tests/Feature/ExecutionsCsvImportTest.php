<?php

use App\Enums\Direction;
use App\Enums\ExitReason;
use App\Mail\FailedImportsMail;
use App\Models\Account;
use App\Models\FailedTradeImport;
use App\Models\Journal;
use App\Models\Trade;
use App\Models\TradeExecution;
use App\Models\User;
use App\Services\ExecutionsCsvImporter;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

uses(LazilyRefreshDatabase::class);

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function csvFixture(string $body): string
{
    $header = "Instrument,Action,Quantity,Price,Time,ID,E/X,Position,Order ID,Name,Commission,Rate,Account,Connection,\n";
    $path   = tempnam(sys_get_temp_dir(), 'csv_test_');
    file_put_contents($path, $header . $body);
    return $path;
}

function importer(): ExecutionsCsvImporter
{
    return app(ExecutionsCsvImporter::class);
}

function journal(array $accountNames = ['Sim101']): Journal
{
    $j = User::factory()->create()->journal;
    $j->update(['timezone' => 'America/New_York']);
    foreach ($accountNames as $name) {
        Account::factory()->create(['journal_id' => $j->id, 'name' => $name]);
    }
    return $j;
}

function journalWithoutTimezone(): Journal
{
    return User::factory()->create()->journal; // timezone is null by default
}

// ---------------------------------------------------------------------------
// Parsing and basic import
// ---------------------------------------------------------------------------

test('a simple long trade is imported with correct P&L', function () {
    $csv = csvFixture(
        "ES 12-26,Buy,1,5000.00,1/2/2026 9:00:00 AM,exec-1,Entry,1 L,ord-1,Entry,\$5.97,1,Sim101,,\n" .
        "ES 12-26,Sell,1,5004.00,1/2/2026 9:05:00 AM,exec-2,Exit,- ,ord-2,Stop,\$5.97,1,Sim101,,\n"
    );

    $journal = journal();
    $result  = importer()->import($journal, $csv);

    expect($result['trades_created'])->toBe(1)
        ->and($result['trades_skipped'])->toBe(0)
        ->and($result['errors'])->toBeEmpty();

    $trade = Trade::first();
    expect($trade->direction)->toBe(Direction::Long)
        ->and((float) $trade->points)->toBe(4.0)
        ->and($trade->ticks)->toBe(16)
        ->and((float) $trade->gross_pnl)->toBe(200.0)   // 4 pts × 1 qty × $50
        ->and((float) $trade->commission)->toBe(11.94)
        ->and((float) $trade->net_pnl)->toBe(188.06)
        ->and($trade->exit_reason)->toBe(ExitReason::Stop);
});

test('a simple short trade is imported with correct P&L', function () {
    $csv = csvFixture(
        "ES 12-26,Sell,1,5010.00,1/2/2026 9:00:00 AM,exec-1,Entry,1 S,ord-1,Entry,\$5.97,1,Sim101,,\n" .
        "ES 12-26,Buy,1,5006.00,1/2/2026 9:05:00 AM,exec-2,Exit,- ,ord-2,Target,\$5.97,1,Sim101,,\n"
    );

    $trade = tap(importer()->import(journal(), $csv), fn () => null);
    $trade = Trade::first();

    expect($trade->direction)->toBe(Direction::Short)
        ->and((float) $trade->points)->toBe(4.0)
        ->and((float) $trade->gross_pnl)->toBe(200.0)
        ->and($trade->exit_reason)->toBe(ExitReason::ProfitTarget);
});

test('executions are stored for each fill', function () {
    $csv = csvFixture(
        "ES 12-26,Buy,1,5000.00,1/2/2026 9:00:00 AM,exec-1,Entry,1 L,ord-1,Entry,\$5.97,1,Sim101,,\n" .
        "ES 12-26,Sell,1,5002.00,1/2/2026 9:05:00 AM,exec-2,Exit,- ,ord-2,Exit,\$5.97,1,Sim101,,\n"
    );

    importer()->import(journal(), $csv);

    expect(TradeExecution::count())->toBe(2)
        ->and(TradeExecution::where('source_execution_id', 'exec-1')->exists())->toBeTrue()
        ->and(TradeExecution::where('source_execution_id', 'exec-2')->exists())->toBeTrue();
});

// ---------------------------------------------------------------------------
// Deduplication
// ---------------------------------------------------------------------------

test('re-importing the same CSV skips already-imported trades', function () {
    $csv = csvFixture(
        "ES 12-26,Buy,1,5000.00,1/2/2026 9:00:00 AM,exec-1,Entry,1 L,ord-1,Entry,\$5.97,1,Sim101,,\n" .
        "ES 12-26,Sell,1,5002.00,1/2/2026 9:05:00 AM,exec-2,Exit,- ,ord-2,Exit,\$5.97,1,Sim101,,\n"
    );

    $journal = journal();
    importer()->import($journal, $csv);

    // Re-create fixture (original was consumed/deleted by importer)
    $csv2 = csvFixture(
        "ES 12-26,Buy,1,5000.00,1/2/2026 9:00:00 AM,exec-1,Entry,1 L,ord-1,Entry,\$5.97,1,Sim101,,\n" .
        "ES 12-26,Sell,1,5002.00,1/2/2026 9:05:00 AM,exec-2,Exit,- ,ord-2,Exit,\$5.97,1,Sim101,,\n"
    );
    $result = importer()->import($journal, $csv2);

    expect($result['trades_created'])->toBe(0)
        ->and($result['trades_skipped'])->toBe(1);
});

test('the same executions in two different journals are not treated as duplicates', function () {
    $csv1 = csvFixture(
        "ES 12-26,Buy,1,5000.00,1/2/2026 9:00:00 AM,exec-1,Entry,1 L,ord-1,Entry,\$5.97,1,Sim101,,\n" .
        "ES 12-26,Sell,1,5002.00,1/2/2026 9:05:00 AM,exec-2,Exit,- ,ord-2,Exit,\$5.97,1,Sim101,,\n"
    );
    $csv2 = csvFixture(
        "ES 12-26,Buy,1,5000.00,1/2/2026 9:00:00 AM,exec-1,Entry,1 L,ord-1,Entry,\$5.97,1,Sim101,,\n" .
        "ES 12-26,Sell,1,5002.00,1/2/2026 9:05:00 AM,exec-2,Exit,- ,ord-2,Exit,\$5.97,1,Sim101,,\n"
    );

    $journalA = journal();
    $journalB = journal();

    importer()->import($journalA, $csv1);
    $result = importer()->import($journalB, $csv2);

    expect($result['trades_created'])->toBe(1)
        ->and(Trade::count())->toBe(2);
});

// ---------------------------------------------------------------------------
// Trade grouping
// ---------------------------------------------------------------------------

test('a scaled-in trade groups all fills into one trade', function () {
    $csv = csvFixture(
        "ES 12-26,Buy,1,5000.00,1/2/2026 9:00:00 AM,exec-1,Entry,1 L,ord-1,Entry,\$5.97,1,Sim101,,\n" .
        "ES 12-26,Buy,2,5001.00,1/2/2026 9:01:00 AM,exec-2,Entry,3 L,ord-2,Entry,\$5.97,1,Sim101,,\n" .
        "ES 12-26,Sell,3,5004.00,1/2/2026 9:05:00 AM,exec-3,Exit,- ,ord-3,Target,\$5.97,1,Sim101,,\n"
    );

    $result = importer()->import(journal(), $csv);

    expect($result['trades_created'])->toBe(1)
        ->and(Trade::first()->quantity)->toBe(3)
        ->and(TradeExecution::count())->toBe(3);
});

test('a reversal fill is split into two trades', function () {
    // Long 1 → sell 2 reverses to short 1 → buy 1 closes
    // NT8 labels the reversal fill as Entry (opening the new direction)
    $csv = csvFixture(
        "ES 12-26,Buy,1,5000.00,1/2/2026 9:00:00 AM,exec-1,Entry,1 L,ord-1,Entry,\$5.97,1,Sim101,,\n" .
        "ES 12-26,Sell,2,5002.00,1/2/2026 9:05:00 AM,exec-2,Entry,1 S,ord-2,Entry,\$5.97,1,Sim101,,\n" .
        "ES 12-26,Buy,1,5001.00,1/2/2026 9:10:00 AM,exec-3,Exit,- ,ord-3,Stop,\$5.97,1,Sim101,,\n"
    );

    $result = importer()->import(journal(), $csv);

    expect($result['trades_created'])->toBe(2)
        ->and($result['errors'])->toBeEmpty();
});

test('fills from different accounts are grouped into separate trades', function () {
    $csv = csvFixture(
        "ES 12-26,Buy,1,5000.00,1/2/2026 9:00:00 AM,exec-1,Entry,1 L,ord-1,Entry,\$5.97,1,AccountA,,\n" .
        "ES 12-26,Sell,1,5002.00,1/2/2026 9:05:00 AM,exec-2,Exit,- ,ord-2,Exit,\$5.97,1,AccountA,,\n" .
        "ES 12-26,Buy,1,5000.00,1/2/2026 9:00:00 AM,exec-3,Entry,1 L,ord-3,Entry,\$5.97,1,AccountB,,\n" .
        "ES 12-26,Sell,1,5002.00,1/2/2026 9:05:00 AM,exec-4,Exit,- ,ord-4,Exit,\$5.97,1,AccountB,,\n"
    );

    $result = importer()->import(journal(['AccountA', 'AccountB']), $csv);

    expect($result['trades_created'])->toBe(2);
});

// ---------------------------------------------------------------------------
// Account parsing
// ---------------------------------------------------------------------------

test('account name with bang separator is parsed correctly', function () {
    $csv = csvFixture(
        "ES 12-26,Buy,1,5000.00,1/2/2026 9:00:00 AM,exec-1,Entry,1 L,ord-1,Entry,\$5.97,1,APEX-24570-135!Apex!Apex,,\n" .
        "ES 12-26,Sell,1,5002.00,1/2/2026 9:05:00 AM,exec-2,Exit,- ,ord-2,Exit,\$5.97,1,APEX-24570-135!Apex!Apex,,\n"
    );

    importer()->import(journal(['APEX-24570-135']), $csv);

    $trade = Trade::with('account')->first();
    expect($trade->account->name)->toBe('APEX-24570-135');
});

// ---------------------------------------------------------------------------
// Validation
// ---------------------------------------------------------------------------

test('a file that is not an NT8 executions export is rejected', function () {
    $path = tempnam(sys_get_temp_dir(), 'csv_bad_');
    file_put_contents($path, "Name,Date,Amount\nFoo,2026-01-01,100\n");

    expect(fn () => importer()->import(journal(), $path))
        ->toThrow(\RuntimeException::class, 'NT8 Trade Performance');
});

test('a trade group with no entry fills records an error and continues', function () {
    // AccountA: valid round-turn. AccountB: two exit-only fills — the grouper
    // can only emit a trade when position returns to 0, so a sell followed by
    // a buy (both labeled Exit) gives an exit-only group.
    $csv = csvFixture(
        "ES 12-26,Buy,1,5000.00,1/2/2026 9:00:00 AM,exec-1,Entry,1 L,ord-1,Entry,\$5.97,1,AccountA,,\n" .
        "ES 12-26,Sell,1,5002.00,1/2/2026 9:05:00 AM,exec-2,Exit,- ,ord-2,Exit,\$5.97,1,AccountA,,\n" .
        "ES 12-26,Sell,1,5002.00,1/2/2026 9:06:00 AM,exec-3,Exit,1 S,ord-3,Exit,\$5.97,1,AccountB,,\n" .
        "ES 12-26,Buy,1,5001.00,1/2/2026 9:07:00 AM,exec-4,Exit,- ,ord-4,Exit,\$5.97,1,AccountB,,\n"
    );

    $result = importer()->import(journal(['AccountA', 'AccountB']), $csv);

    expect($result['trades_created'])->toBe(1)
        ->and(count($result['errors']))->toBe(1);
});

test('a malformed row is recorded as a failed import instead of aborting the import', function (string $badRow, string $reason) {
    // AccountA: valid round-turn. AccountB: one good fill plus one malformed
    // fill. Dropping just the bad fill would corrupt AccountB's position
    // tracking, so AccountB/ES is withheld entirely while AccountA imports.
    $csv = csvFixture(
        "ES 12-26,Buy,1,5000.00,1/2/2026 9:00:00 AM,exec-1,Entry,1 L,ord-1,Entry,\$5.97,1,AccountA,,\n" .
        "ES 12-26,Sell,1,5002.00,1/2/2026 9:05:00 AM,exec-2,Exit,- ,ord-2,Exit,\$5.97,1,AccountA,,\n" .
        "ES 12-26,Buy,1,5000.00,1/2/2026 9:00:00 AM,exec-3,Entry,1 L,ord-3,Entry,\$5.97,1,AccountB,,\n" .
        $badRow . "\n"
    );

    $journal = journal(['AccountA', 'AccountB']);
    Mail::fake();

    $result = importer()->import($journal, $csv);

    expect($result['trades_created'])->toBe(1)
        ->and(Trade::count())->toBe(1)
        ->and($result['errors'])->not->toBeEmpty();

    $failure = FailedTradeImport::sole();
    expect($failure->journal_id)->toBe($journal->id)
        ->and($failure->account_name)->toBe('AccountB')
        ->and($failure->reason)->toContain('Row 5')
        ->and($failure->reason)->toContain($reason);

    Mail::assertSent(FailedImportsMail::class);
})->with([
    'bad date'         => ['ES 12-26,Sell,1,5002.00,not-a-date,exec-4,Exit,- ,ord-4,Exit,$5.97,1,AccountB,,', 'time'],
    'overflowing date' => ['ES 12-26,Sell,1,5002.00,13/45/2026 9:05:00 AM,exec-4,Exit,- ,ord-4,Exit,$5.97,1,AccountB,,', 'time'],
    'non-numeric price' => ['ES 12-26,Sell,1,abc,1/2/2026 9:05:00 AM,exec-4,Exit,- ,ord-4,Exit,$5.97,1,AccountB,,', 'price'],
    'bad quantity'     => ['ES 12-26,Sell,x,5002.00,1/2/2026 9:05:00 AM,exec-4,Exit,- ,ord-4,Exit,$5.97,1,AccountB,,', 'quantity'],
]);

// ---------------------------------------------------------------------------
// Timezone guard
// ---------------------------------------------------------------------------

test('import is rejected when the journal has no timezone set', function () {
    $csv = csvFixture(
        "ES 12-26,Buy,1,5000.00,1/2/2026 9:00:00 AM,exec-tz1,Entry,1 L,ord-1,Entry,\$5.97,1,Sim101,,\n" .
        "ES 12-26,Sell,1,5004.00,1/2/2026 9:05:00 AM,exec-tz2,Exit,- ,ord-2,Stop,\$5.97,1,Sim101,,\n"
    );

    $result = importer()->import(journalWithoutTimezone(), $csv);

    expect($result['trades_created'])->toBe(0)
        ->and($result['trades_skipped'])->toBe(0)
        ->and($result['errors'])->toHaveCount(1)
        ->and($result['errors'][0])->toContain('timezone');
});

test('each account timestamp is parsed using that account own timezone, not the journal default', function () {
    $journal = journal(); // journal timezone: America/New_York
    Account::factory()->create([
        'journal_id' => $journal->id,
        'name'       => 'PacificAccount',
        'timezone'   => 'America/Los_Angeles', // 3 hours behind New York
    ]);

    // Identical local wall-clock time on both accounts.
    $csv = csvFixture(
        "ES 12-26,Buy,1,5000.00,1/2/2026 9:00:00 AM,exec-ny1,Entry,1 L,ord-1,Entry,\$5.97,1,Sim101,,\n" .
        "ES 12-26,Sell,1,5004.00,1/2/2026 9:05:00 AM,exec-ny2,Exit,- ,ord-2,Stop,\$5.97,1,Sim101,,\n" .
        "ES 12-26,Buy,1,5000.00,1/2/2026 9:00:00 AM,exec-la1,Entry,1 L,ord-3,Entry,\$5.97,1,PacificAccount,,\n" .
        "ES 12-26,Sell,1,5004.00,1/2/2026 9:05:00 AM,exec-la2,Exit,- ,ord-4,Stop,\$5.97,1,PacificAccount,,\n"
    );

    $result = importer()->import($journal, $csv);
    expect($result['trades_created'])->toBe(2);

    $nyTrade = Trade::whereHas('account', fn ($q) => $q->where('name', 'Sim101'))->firstOrFail();
    $laTrade = Trade::whereHas('account', fn ($q) => $q->where('name', 'PacificAccount'))->firstOrFail();

    // Same local wall-clock time, 3 hours apart once converted to UTC.
    expect($nyTrade->entry_at->diffInHours($laTrade->entry_at))->toBe(3.0);
});
