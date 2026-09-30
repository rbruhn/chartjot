<?php

use App\Enums\Direction;
use App\Enums\ExitReason;
use App\Enums\TradeType;
use App\Models\Account;
use App\Models\Journal;
use App\Models\Trade;
use App\Models\TradeLeg;
use App\Models\User;
use App\Support\TradeStatistics;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;

uses(LazilyRefreshDatabase::class);

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function statsJournal(string $timezone = 'UTC'): array
{
    $journal = User::factory()->create()->journal;
    $journal->update(['timezone' => $timezone]);
    $account = Account::factory()->create(['journal_id' => $journal->id, 'timezone' => null]);

    return [$journal, $account];
}

/** A trade with only the fields a statistic reads set explicitly; the rest are neutral. */
function statTrade(Account $account, array $attrs = []): Trade
{
    $net = $attrs['net_pnl'] ?? 0;

    return Trade::factory()->create(array_merge([
        'journal_id'           => $account->journal_id,
        'account_id'           => $account->id,
        'instrument_symbol'    => 'ES',
        'instrument'           => 'ES 12-26',
        'point_value'          => '50.00',
        'direction'            => Direction::Long,
        'trade_type'           => TradeType::SecondEntryLong,
        'exit_reason'          => ExitReason::ProfitTarget,
        'quantity'             => 1,
        'total_entry_quantity' => 1,
        'gross_pnl'            => $net,
        'commission'           => 0,
        'fees'                 => null,
        'excursion_mae_points' => null,
        'excursion_mfe_points' => null,
        'excursion_complete'   => false,
    ], $attrs));
}

/** Loads the journal's trades exactly the way the statistics page does. */
function statsFor(Journal $journal): TradeStatistics
{
    return new TradeStatistics(
        $journal->trades()->with(['account.journal', 'legs'])->orderBy('entry_at')->get()
    );
}

/**
 * Six trades, in entry order, with net P&L:
 *   +300, +200, -100, 0 (breakeven), -150, +50
 *
 * Hand-computed expectations (breakeven counts as a loss, matching the
 * journal page's summary()):
 *   wins 3 / losses 3           → win rate 50%
 *   net 300 / 6 trades          → expectancy 50
 *   gross wins 550 / losses 250 → profit factor 2.2
 *   avg win 550/3, avg loss -250/3
 *   largest win 300, largest loss -150
 *   streaks W W L L L W         → max 2 wins, 3 losses (the 0 extends the loss streak)
 *   cumulative 300 500 400 400 250 300 → max drawdown 500 - 250 = 250
 */
function sixKnownTrades(Account $account): void
{
    $start = Carbon::parse('2026-03-02 14:00:00', 'UTC');

    foreach ([300, 200, -100, 0, -150, 50] as $i => $net) {
        statTrade($account, [
            'net_pnl'  => $net,
            'entry_at' => $start->copy()->addDays($i),
            'exit_at'  => $start->copy()->addDays($i)->addMinutes(10),
        ]);
    }
}

// ---------------------------------------------------------------------------
// Core summary math
// ---------------------------------------------------------------------------

test('win rate, expectancy and profit factor match hand-computed values', function () {
    [$journal, $account] = statsJournal();
    sixKnownTrades($account);

    $s = statsFor($journal)->summary();

    expect($s['total'])->toBe(6)
        ->and($s['wins'])->toBe(3)
        ->and($s['losses'])->toBe(3)
        ->and($s['win_rate'])->toBe(50.0)
        ->and($s['net_pnl'])->toBe(300.0)
        ->and($s['expectancy'])->toBe(50.0)
        ->and($s['profit_factor'])->toBe(2.2)
        ->and($s['avg_win'])->toEqualWithDelta(183.333, 0.001)
        ->and($s['avg_loss'])->toEqualWithDelta(-83.333, 0.001)
        ->and($s['largest_win'])->toBe(300.0)
        ->and($s['largest_loss'])->toBe(-150.0);
});

test('streaks treat breakeven as a loss and drawdown is peak to trough', function () {
    [$journal, $account] = statsJournal();
    sixKnownTrades($account);

    $s = statsFor($journal)->summary();

    expect($s['max_win_streak'])->toBe(2)
        ->and($s['max_loss_streak'])->toBe(3)
        ->and($s['max_drawdown'])->toBe(250.0);
});

test('profit factor is null when there are no losing dollars', function () {
    [$journal, $account] = statsJournal();
    statTrade($account, ['net_pnl' => 100]);

    expect(statsFor($journal)->summary()['profit_factor'])->toBeNull();
});

test('an empty trade set produces zeroed stats rather than dividing by zero', function () {
    [$journal] = statsJournal();

    $s = statsFor($journal)->summary();

    expect($s['total'])->toBe(0)
        ->and($s['win_rate'])->toBe(0.0)
        ->and($s['expectancy'])->toBe(0.0)
        ->and($s['profit_factor'])->toBeNull()
        ->and($s['largest_win'])->toBeNull()
        ->and($s['max_drawdown'])->toBe(0.0);
});

// ---------------------------------------------------------------------------
// Breakdowns
// ---------------------------------------------------------------------------

test('day, hour and calendar buckets use the account local time, not UTC', function () {
    [$journal, $account] = statsJournal('America/New_York');

    // 03:30 UTC Tuesday 3 March = 22:30 EST Monday 2 March.
    statTrade($account, [
        'net_pnl'  => 120,
        'entry_at' => Carbon::parse('2026-03-03 03:30:00', 'UTC'),
        'exit_at'  => Carbon::parse('2026-03-03 03:45:00', 'UTC'),
    ]);

    $stats = statsFor($journal);

    expect($stats->byDayOfWeek()->pluck('label')->all())->toBe(['Monday'])
        ->and($stats->byHour()->pluck('label')->all())->toBe(['22:00'])
        ->and($stats->daily()->keys()->all())->toBe(['2026-03-02']);
});

test('win rate by trade type, P&L by instrument, exit reason and direction', function () {
    [$journal, $account] = statsJournal();

    statTrade($account, ['net_pnl' => 100, 'trade_type' => TradeType::SecondEntryLong, 'instrument_symbol' => 'ES', 'exit_reason' => ExitReason::ProfitTarget, 'direction' => Direction::Long]);
    statTrade($account, ['net_pnl' => -40, 'trade_type' => TradeType::SecondEntryLong, 'instrument_symbol' => 'NQ', 'exit_reason' => ExitReason::Stop, 'direction' => Direction::Long]);
    statTrade($account, ['net_pnl' => 60, 'trade_type' => TradeType::RangeShort, 'instrument_symbol' => 'NQ', 'exit_reason' => ExitReason::ProfitTarget, 'direction' => Direction::Short]);

    $stats = statsFor($journal);

    $byType = $stats->byTradeType()->keyBy('label');
    expect($byType['Second Entry Long']['count'])->toBe(2)
        ->and($byType['Second Entry Long']['win_rate'])->toBe(50.0)
        ->and($byType['Range Short']['win_rate'])->toBe(100.0);

    expect($stats->byInstrument()->pluck('net_pnl', 'label')->all())->toBe(['ES' => 100.0, 'NQ' => 20.0])
        ->and($stats->byExitReason()->pluck('net_pnl', 'label')->all())->toBe(['Profit Target' => 160.0, 'Stop' => -40.0])
        ->and($stats->byDirection()->pluck('net_pnl', 'label')->all())->toBe(['Long' => 60.0, 'Short' => 60.0]);
});

test('average duration is split between winners and losers', function () {
    [$journal, $account] = statsJournal();
    $t = Carbon::parse('2026-03-02 14:00:00', 'UTC');

    statTrade($account, ['net_pnl' => 50, 'entry_at' => $t, 'exit_at' => $t->copy()->addMinutes(10)]);
    statTrade($account, ['net_pnl' => 70, 'entry_at' => $t, 'exit_at' => $t->copy()->addMinutes(20)]);
    statTrade($account, ['net_pnl' => -30, 'entry_at' => $t, 'exit_at' => $t->copy()->addMinutes(3)]);

    $d = statsFor($journal)->durations();

    expect($d['winners'])->toBe(900.0)   // (600 + 1200) / 2 seconds
        ->and($d['losers'])->toBe(180.0)
        ->and($d['all'])->toBe(660.0);
});

test('commission and fees are reported as a share of gross P&L', function () {
    [$journal, $account] = statsJournal();

    statTrade($account, ['gross_pnl' => 400, 'commission' => 30, 'fees' => 10, 'net_pnl' => 360]);
    statTrade($account, ['gross_pnl' => 100, 'commission' => 10, 'fees' => null, 'net_pnl' => 90]);

    $c = statsFor($journal)->costs();

    expect($c['total'])->toBe(50.0)
        ->and($c['pct_of_gross'])->toBe(10.0);   // 50 / 500
});

test('MAE and MFE only use trades with a complete excursion', function () {
    [$journal, $account] = statsJournal();

    // Complete: MFE 4 pts × $50 × 2 contracts = $400 available, $300 banked.
    statTrade($account, ['net_pnl' => 300, 'total_entry_quantity' => 2, 'excursion_mae_points' => 1, 'excursion_mfe_points' => 4, 'excursion_complete' => true]);
    // Complete: MFE 2 pts × $50 × 1 = $100 available, $-50 banked.
    statTrade($account, ['net_pnl' => -50, 'excursion_mae_points' => 3, 'excursion_mfe_points' => 2, 'excursion_complete' => true]);
    // Incomplete (feed interrupted): values are lower bounds, excluded.
    statTrade($account, ['net_pnl' => 500, 'excursion_mae_points' => 0.25, 'excursion_mfe_points' => 0.5, 'excursion_complete' => false]);
    // No excursion data at all (e.g. CSV import).
    statTrade($account, ['net_pnl' => 10]);

    $e = statsFor($journal)->excursion();

    expect($e['trades'])->toBe(2)
        ->and($e['incomplete'])->toBe(1)
        ->and($e['avg_mae_points'])->toBe(2.0)
        ->and($e['avg_mfe_points'])->toBe(3.0)
        ->and($e['avg_mae_dollars'])->toBe(125.0)   // (1×50×2 + 3×50×1) / 2
        ->and($e['avg_mfe_dollars'])->toBe(250.0)   // (400 + 100) / 2
        ->and($e['capture_ratio'])->toBe(50.0);     // (300 - 50) / 500
});

test('runner contribution is the runner legs share of leg gross P&L', function () {
    [$journal, $account] = statsJournal();

    $trade = statTrade($account, ['net_pnl' => 380, 'gross_pnl' => 400]);
    TradeLeg::factory()->create(['trade_id' => $trade->id, 'sequence' => 1, 'runner' => false, 'gross_pnl' => 100]);
    TradeLeg::factory()->create(['trade_id' => $trade->id, 'sequence' => 2, 'runner' => true, 'gross_pnl' => 300]);
    statTrade($account, ['net_pnl' => 50]); // no legs: not part of the runner split

    $r = statsFor($journal)->runners();

    expect($r['trades'])->toBe(1)
        ->and($r['runner_gross'])->toBe(300.0)
        ->and($r['base_gross'])->toBe(100.0)
        ->and($r['runner_share'])->toBe(75.0);
});

// ---------------------------------------------------------------------------
// Equity curve
// ---------------------------------------------------------------------------

test('equity curve adds deposits, subtracts withdrawals and accumulates P&L by day', function () {
    $curve = TradeStatistics::equityCurve(
        50000.0,
        collect([
            ['date' => '2026-03-03', 'amount' => 1000.0],
            ['date' => '2026-03-04', 'amount' => -2000.0],
        ]),
        collect(['2026-03-02' => ['pnl' => 300.0], '2026-03-03' => ['pnl' => -100.0], '2026-03-04' => ['pnl' => 200.0]]),
    );

    expect($curve->pluck('balance', 'date')->all())->toBe([
        '2026-03-02' => 50300.0,
        '2026-03-03' => 51200.0,   // +1000 deposit, -100 P&L
        '2026-03-04' => 49400.0,   // -2000 withdrawal, +200 P&L
    ]);
});

test('a cash-flow-only day still appears on the equity curve', function () {
    $curve = TradeStatistics::equityCurve(
        0.0,
        collect([['date' => '2026-03-01', 'amount' => 5000.0]]),
        collect(['2026-03-02' => ['pnl' => 100.0]]),
    );

    expect($curve->pluck('balance', 'date')->all())->toBe(['2026-03-01' => 5000.0, '2026-03-02' => 5100.0]);
});
