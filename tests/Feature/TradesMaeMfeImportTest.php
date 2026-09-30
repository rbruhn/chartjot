<?php

use App\Enums\ExitReason;
use App\Models\Account;
use App\Models\FailedTradeImport;
use App\Models\Journal;
use App\Models\Trade;
use App\Models\TradeLeg;
use App\Models\User;
use App\Services\ExecutionsCsvImporter;
use App\Services\TradesMaeMfeImporter;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(LazilyRefreshDatabase::class);

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

const TRADES_HEADER = "Trade number,Instrument,Account,Strategy,Market pos.,Qty,Entry price,Exit price,Entry time,Exit time,Entry name,Exit name,Profit,Cum. net profit,Commission,Clearing Fee,Exchange Fee,IP Fee,NFA Fee,MAE,MFE,ETD,Bars,\n";
const EXECUTIONS_HEADER = "Instrument,Action,Quantity,Price,Time,ID,E/X,Position,Order ID,Name,Commission,Rate,Account,Connection,\n";

function maeJournal(array $accounts = ['Sim101'], ?string $accountTimezone = null): Journal
{
    $journal = User::factory()->create()->journal;
    $journal->update(['timezone' => 'America/New_York']);
    foreach ($accounts as $name) {
        Account::factory()->create(['journal_id' => $journal->id, 'name' => $name, 'timezone' => $accountTimezone]);
    }

    return $journal;
}

function tempCsv(string $header, string $body): string
{
    $path = tempnam(sys_get_temp_dir(), 'nt8_');
    file_put_contents($path, $header.$body);

    return $path;
}

function importExecutions(Journal $journal, string $body): array
{
    return app(ExecutionsCsvImporter::class)->import($journal, tempCsv(EXECUTIONS_HEADER, $body));
}

function importTradesCsv(Journal $journal, string $body): array
{
    return app(TradesMaeMfeImporter::class)->import($journal, tempCsv(TRADES_HEADER, $body));
}

/** One Trades-export row. MAE/MFE are dollar amounts for the row's whole quantity, as NT8 exports them. */
function tradesRow(array $o = []): string
{
    $o += [
        'n' => 1, 'instrument' => 'ES 12-26', 'account' => 'Sim101', 'pos' => 'Long', 'qty' => 1,
        'entry' => '5000.00', 'exit' => '5004.00', 'in' => '1/2/2026 9:00:00 AM', 'out' => '1/2/2026 9:05:00 AM',
        'entry_name' => 'Entry', 'exit_name' => 'Target1', 'mae' => '$50.00', 'mfe' => '$250.00',
    ];

    return "{$o['n']},{$o['instrument']},{$o['account']},,{$o['pos']},{$o['qty']},{$o['entry']},{$o['exit']},{$o['in']},{$o['out']},"
        ."{$o['entry_name']},{$o['exit_name']},\$196.02,\$196.02,\$3.98,\$0.00,\$0.00,\$0.00,\$0.00,{$o['mae']},{$o['mfe']},\$53.98,0,\n";
}

/** Long 3 ES at 5000; 2 exit at 5004 (Target1) at 9:05, 1 at 5006 (Target2) at 9:07. */
function threeContractTrade(Journal $journal): Trade
{
    importExecutions($journal,
        "ES 12-26,Buy,3,5000.00,1/2/2026 9:00:00 AM,e1,Entry,3 L,o1,Entry,\$5.97,1,Sim101,,\n".
        "ES 12-26,Sell,2,5004.00,1/2/2026 9:05:00 AM,e2,Exit,1 L,o2,Target1,\$3.98,1,Sim101,,\n".
        "ES 12-26,Sell,1,5006.00,1/2/2026 9:07:00 AM,e3,Exit,-,o3,Target2,\$1.99,1,Sim101,,\n"
    );

    return Trade::sole();
}

// ---------------------------------------------------------------------------
// Building legs
// ---------------------------------------------------------------------------

test('a two-exit trade gets two legs with MAE/MFE converted from dollars to points', function () {
    $journal = maeJournal();
    $trade = threeContractTrade($journal);

    // Rows deliberately out of exit order: sequence must come from exit time, not file order.
    $result = importTradesCsv($journal,
        tradesRow(['n' => 2, 'qty' => 1, 'exit' => '5006.00', 'out' => '1/2/2026 9:07:00 AM', 'exit_name' => 'Target2', 'mae' => '$75.00', 'mfe' => '$325.00']).
        tradesRow(['n' => 1, 'qty' => 2, 'exit' => '5004.00', 'out' => '1/2/2026 9:05:00 AM', 'exit_name' => 'Target1', 'mae' => '$150.00', 'mfe' => '$450.00'])
    );

    expect($result['errors'])->toBeEmpty()
        ->and($result['trades_enriched'])->toBe(1)
        ->and($result['legs_imported'])->toBe(2);

    [$first, $second] = $trade->legs()->get()->all();

    // $150 MAE on 2 ES contracts ($50/pt) = 1.5 points; $450 MFE = 4.5 points.
    expect($first->sequence)->toBe(1)
        ->and($first->quantity)->toBe(2)
        ->and((float) $first->average_exit_price)->toBe(5004.0)
        ->and((float) $first->points)->toBe(4.0)
        ->and((float) $first->gross_pnl)->toBe(400.0)      // 4 pts × 2 × $50 — gross, from prices
        ->and((float) $first->mae_points)->toBe(1.5)
        ->and((float) $first->mfe_points)->toBe(4.5)
        ->and($first->order_name)->toBe('Target1')
        ->and($first->exit_order_id)->toBe('Target1')
        ->and($first->reason)->toBe(ExitReason::ProfitTarget)
        ->and($first->runner)->toBeFalse();

    expect($second->sequence)->toBe(2)
        ->and((float) $second->points)->toBe(6.0)
        ->and((float) $second->gross_pnl)->toBe(300.0)
        ->and((float) $second->mae_points)->toBe(1.5)
        ->and((float) $second->mfe_points)->toBe(6.5)
        ->and($second->runner)->toBeTrue();

    $trade->refresh();
    expect((float) $trade->excursion_mae_points)->toBe(1.5)
        ->and((float) $trade->excursion_mfe_points)->toBe(6.5)
        ->and($trade->excursion_complete)->toBeTrue();
});

test('a single-exit trade gets one leg, which is not a runner', function () {
    $journal = maeJournal();
    importExecutions($journal,
        "ES 12-26,Buy,1,5000.00,1/2/2026 9:00:00 AM,e1,Entry,1 L,o1,Entry,\$1.99,1,Sim101,,\n".
        "ES 12-26,Sell,1,4998.00,1/2/2026 9:03:00 AM,e2,Exit,-,o2,Stop1,\$1.99,1,Sim101,,\n"
    );

    importTradesCsv($journal, tradesRow(['exit' => '4998.00', 'out' => '1/2/2026 9:03:00 AM', 'exit_name' => 'Stop1', 'mae' => '$125.00', 'mfe' => '$25.00']));

    $leg = TradeLeg::sole();
    expect($leg->sequence)->toBe(1)
        ->and((float) $leg->points)->toBe(-2.0)
        ->and((float) $leg->mae_points)->toBe(2.5)
        ->and((float) $leg->mfe_points)->toBe(0.5)
        ->and($leg->reason)->toBe(ExitReason::Stop)
        ->and($leg->runner)->toBeFalse();
    expect(Trade::sole()->excursion_complete)->toBeTrue();
});

test('rows that exit in the same second as the first exit are not runners', function () {
    $journal = maeJournal();
    threeContractTrade($journal);

    // Both rows exit together at 9:05 — nothing was held past the first exit.
    importTradesCsv($journal,
        tradesRow(['qty' => 2, 'out' => '1/2/2026 9:05:00 AM', 'exit_name' => 'Target1', 'mae' => '$100.00', 'mfe' => '$400.00']).
        tradesRow(['qty' => 1, 'out' => '1/2/2026 9:05:00 AM', 'exit_name' => 'Target2', 'mae' => '$50.00', 'mfe' => '$200.00'])
    );

    expect(TradeLeg::pluck('runner')->all())->toBe([false, false]);
});

test('legs are priced from the exact average entry, so they add up to the trade gross P&L', function () {
    $journal = maeJournal();
    // 1 @ 5000.00 + 7 @ 5000.25 averages 5000.21875; trades.entry_price keeps 4 decimals
    // (5000.2188), which on these two legs would lose 2 cents the trade has.
    importExecutions($journal,
        "ES 12-26,Buy,1,5000.00,1/2/2026 9:00:00 AM,e1,Entry,1 L,o1,Entry,\$1.99,1,Sim101,,\n".
        "ES 12-26,Buy,7,5000.25,1/2/2026 9:01:00 AM,e2,Entry,8 L,o2,Entry,\$13.93,1,Sim101,,\n".
        "ES 12-26,Sell,8,5004.00,1/2/2026 9:05:00 AM,e3,Exit,-,o3,Target1,\$15.92,1,Sim101,,\n"
    );

    importTradesCsv($journal,
        tradesRow(['n' => 1, 'qty' => 1, 'entry' => '5000.00', 'in' => '1/2/2026 9:00:00 AM']).
        tradesRow(['n' => 2, 'qty' => 7, 'entry' => '5000.25', 'in' => '1/2/2026 9:01:00 AM'])
    );

    $trade = Trade::sole();
    expect((float) $trade->gross_pnl)->toBe(1512.5)
        ->and(round(TradeLeg::sum('gross_pnl'), 2))->toBe(1512.5);
});

test('a scale-in row matches its trade even though its entry time is later than the trade entry', function () {
    $journal = maeJournal();
    // 1 at 9:00, add 1 at 9:02 (scale-in), both out at 9:05. The Trades export pairs the second
    // contract with its own 9:02 entry, so matching on exact entry time would miss it.
    importExecutions($journal,
        "ES 12-26,Buy,1,5000.00,1/2/2026 9:00:00 AM,e1,Entry,1 L,o1,Entry,\$1.99,1,Sim101,,\n".
        "ES 12-26,Buy,1,4999.00,1/2/2026 9:02:00 AM,e2,Entry,2 L,o2,Entry,\$1.99,1,Sim101,,\n".
        "ES 12-26,Sell,2,5003.00,1/2/2026 9:05:00 AM,e3,Exit,-,o3,Target1,\$3.98,1,Sim101,,\n"
    );

    $result = importTradesCsv($journal,
        tradesRow(['n' => 1, 'entry' => '5000.00', 'exit' => '5003.00', 'in' => '1/2/2026 9:00:00 AM']).
        tradesRow(['n' => 2, 'entry' => '4999.00', 'exit' => '5003.00', 'in' => '1/2/2026 9:02:00 AM'])
    );

    expect($result['errors'])->toBeEmpty()->and(TradeLeg::count())->toBe(2);
});

test('times are read in each account own timezone, as the Executions import does', function () {
    // Sim101 is on Chicago time while the journal is on New York time. Both exports show
    // Chicago-local clock times; reading the Trades file in the journal's zone would put
    // every row an hour off and match nothing.
    $journal = maeJournal(['Sim101'], 'America/Chicago');
    threeContractTrade($journal);

    $result = importTradesCsv($journal,
        tradesRow(['qty' => 2, 'out' => '1/2/2026 9:05:00 AM']).
        tradesRow(['qty' => 1, 'exit' => '5006.00', 'out' => '1/2/2026 9:07:00 AM', 'exit_name' => 'Target2'])
    );

    expect($result['errors'])->toBeEmpty()->and(TradeLeg::count())->toBe(2);
});

// ---------------------------------------------------------------------------
// Re-import safety & what it must never touch
// ---------------------------------------------------------------------------

test('re-uploading the same file is idempotent', function () {
    $journal = maeJournal();
    $trade = threeContractTrade($journal);
    $body = tradesRow(['qty' => 2, 'mae' => '$150.00']).tradesRow(['qty' => 1, 'exit' => '5006.00', 'out' => '1/2/2026 9:07:00 AM', 'exit_name' => 'Target2']);

    importTradesCsv($journal, $body);
    $first = $trade->legs()->get()->map->only(['sequence', 'quantity', 'mae_points', 'mfe_points', 'runner'])->all();
    $again = importTradesCsv($journal, $body);

    expect($again['errors'])->toBeEmpty()
        ->and(TradeLeg::count())->toBe(2)
        ->and($trade->legs()->get()->map->only(['sequence', 'quantity', 'mae_points', 'mfe_points', 'runner'])->all())->toBe($first)
        ->and(FailedTradeImport::count())->toBe(0);
});

test('it never creates a trade', function () {
    $journal = maeJournal();

    importTradesCsv($journal, tradesRow());

    expect(Trade::count())->toBe(0)->and(TradeLeg::count())->toBe(0);
});

test('an AddOn-reported trade keeps its own legs and excursion', function () {
    $journal = maeJournal();
    $trade = threeContractTrade($journal);
    $trade->update(['source' => 'ninjatrader_8', 'excursion_mae_points' => 9, 'excursion_mfe_points' => 9]);
    TradeLeg::factory()->create(['trade_id' => $trade->id, 'mae_points' => 9, 'mfe_points' => 9]);

    $result = importTradesCsv($journal, tradesRow(['qty' => 3]));

    expect($result['rows_skipped'])->toBe(1)
        ->and($result['errors'])->toBeEmpty()
        ->and(TradeLeg::sole()->mae_points)->toEqual(9)
        ->and((float) $trade->fresh()->excursion_mae_points)->toBe(9.0);
});

// ---------------------------------------------------------------------------
// Failures
// ---------------------------------------------------------------------------

test('a row with no matching trade is recorded as a failure with its raw row, and returned for the completion email', function () {
    $journal = maeJournal();
    threeContractTrade($journal);
    Mail::fake();

    $result = importTradesCsv($journal,
        tradesRow(['qty' => 2]).tradesRow(['qty' => 1, 'exit' => '5006.00', 'out' => '1/2/2026 9:07:00 AM']).
        tradesRow(['n' => 9, 'instrument' => 'NQ 12-26', 'in' => '1/2/2026 11:00:00 AM', 'out' => '1/2/2026 11:05:00 AM'])
    );

    expect($result['trades_enriched'])->toBe(1)->and($result['errors'])->toHaveCount(1);

    $failure = FailedTradeImport::sole();
    expect($failure->account_name)->toBe('Sim101')
        ->and($failure->source_trade_id)->toBeNull()
        ->and($failure->reason)->toContain('No matching trade')
        ->and($failure->reason)->toContain('NQ 12-26')
        ->and($failure->reason)->toContain('1/2/2026 11:00:00 AM')
        ->and($failure->payload['Trade number'])->toBe('9')
        ->and($failure->payload['Instrument'])->toBe('NQ 12-26');

    expect(Trade::count())->toBe(1);
    // Returned without the raw row, for the job's completion email; the importer itself sends nothing.
    expect($result['failures'])->toHaveCount(1)
        ->and($result['failures'][0])->toHaveKeys(['account_name', 'source_trade_id', 'reason', 'occurred_at'])
        ->and($result['failures'][0])->not->toHaveKey('payload');
    Mail::assertNothingSent();
});

test('a matched trade whose rows do not cover all its contracts is a distinct failure and gets no legs', function () {
    $journal = maeJournal();
    $trade = threeContractTrade($journal);

    // Only the 2-contract row: 2 of 3 contracts, e.g. an export cut off by a date filter.
    importTradesCsv($journal, tradesRow(['qty' => 2]));

    $failure = FailedTradeImport::sole();
    expect($failure->reason)->toContain('cover 2 of 3 contracts')
        ->and($failure->reason)->not->toContain('No matching trade')
        ->and($failure->source_trade_id)->toBe($trade->source_trade_id)
        ->and(TradeLeg::count())->toBe(0)
        ->and($trade->fresh()->excursion_complete)->toBeFalse();
});

test('MAE/MFE exported in a unit other than currency is rejected rather than guessed', function () {
    $journal = maeJournal();
    threeContractTrade($journal);

    importTradesCsv($journal, tradesRow(['qty' => 3, 'mae' => '1.5', 'mfe' => '4.5']));

    expect(FailedTradeImport::sole()->reason)->toContain('currency')
        ->and(TradeLeg::count())->toBe(0);
});

test('a malformed row is a failure and the rest of the file still imports', function () {
    $journal = maeJournal();
    threeContractTrade($journal);

    $result = importTradesCsv($journal,
        tradesRow(['qty' => 2]).tradesRow(['qty' => 1, 'exit' => '5006.00', 'out' => '1/2/2026 9:07:00 AM']).
        tradesRow(['n' => 7, 'in' => 'not-a-date'])
    );

    expect($result['trades_enriched'])->toBe(1)
        ->and(FailedTradeImport::sole()->reason)->toContain('Row 4')
        ->and(FailedTradeImport::sole()->reason)->toContain('entry time');
});

test('a file that is not a Trades export is rejected', function () {
    $journal = maeJournal();

    expect(fn () => app(TradesMaeMfeImporter::class)->import($journal, tempCsv(EXECUTIONS_HEADER, '')))
        ->toThrow(RuntimeException::class, 'Trades');
});

test('import is refused when the journal has no timezone', function () {
    $journal = maeJournal();
    $journal->update(['timezone' => null]);

    expect(importTradesCsv($journal, tradesRow())['errors'][0])->toContain('timezone');
});

// ---------------------------------------------------------------------------
// End to end on tests/Fixtures/ninjatrader/{Executions,Trades}.csv: hand-made,
// fictional data in NinjaTrader 8's exact export format (same columns, $/($…)
// amounts, trailing commas, CRLF). No real account or trade data. Across three
// trades on SimTest1/SimTest2 they cover a scale-in whose rows carry a later
// entry time, a multi-leg exit with a runner, MAE/MFE as currency including a
// quoted "$1,025.00", and a parenthesized negative Profit. Every figure is
// consistent: Profit = gross − commission, MAE/MFE ÷ (qty × point value) = ticks.
// ---------------------------------------------------------------------------

test('Executions + Trades exports enrich every trade with no failures', function () {
    $journal = maeJournal(['SimTest1', 'SimTest2']);
    $fixtures = base_path('tests/Fixtures/ninjatrader');

    $executions = app(ExecutionsCsvImporter::class)->import($journal, "$fixtures/Executions.csv");
    expect($executions['errors'])->toBeEmpty()->and(Trade::count())->toBe(3);

    $result = app(TradesMaeMfeImporter::class)->import($journal, "$fixtures/Trades.csv");

    expect($result['errors'])->toBeEmpty()
        ->and($result['legs_imported'])->toBe(5)
        ->and($result['trades_enriched'])->toBe(3)
        ->and(Trade::where('excursion_complete', false)->count())->toBe(0);

    // Legs are priced from the trade's exact average entry, so they add up to the trade's gross
    // P&L — to within each leg's rounding to the cent.
    foreach (Trade::with('legs')->get() as $trade) {
        expect(round($trade->legs->sum(fn ($l) => (float) $l->gross_pnl), 2))
            ->toEqualWithDelta((float) $trade->gross_pnl, 0.005 * $trade->legs->count() + 0.0001);
    }

    // The scale-in trade: SimTest1 long 2 at 9:30, +3 at 9:31 (their rows carry 9:31), out 3 at
    // 9:40 and 2 at 9:45.
    $scaleIn = Trade::whereHas('account', fn ($q) => $q->where('name', 'SimTest1'))
        ->where('entry_at', \Illuminate\Support\Carbon::parse('2026-01-05 14:30:00', 'UTC'))->sole();
    expect($scaleIn->legs)->toHaveCount(3)
        ->and((float) $scaleIn->excursion_mae_points)->toBe(2.5)     // $250.00 on 2 ES contracts
        ->and((float) $scaleIn->excursion_mfe_points)->toBe(10.25)   // "$1,025.00" on 2 ES contracts
        ->and($scaleIn->legs->where('runner', false)->count())->toBe(2) // both 9:40 exits
        ->and($scaleIn->legs->last()->runner)->toBeTrue();              // the 9:45 exit
});
