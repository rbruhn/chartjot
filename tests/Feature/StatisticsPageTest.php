<?php

use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\Journal;
use App\Models\Trade;
use App\Models\TradeLeg;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

uses(LazilyRefreshDatabase::class);

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function statsPageUser(): array
{
    $user    = User::factory()->create();
    $journal = $user->journal;
    $journal->update(['timezone' => 'UTC']);

    return [$user, $journal];
}

function statsAccount(Journal $journal, array $attrs = []): Account
{
    return Account::factory()->create(array_merge(['journal_id' => $journal->id, 'timezone' => null], $attrs));
}

function statsPageTrade(Account $account, float $net, string $entryAt): Trade
{
    $at = Carbon::parse($entryAt, 'UTC');

    return Trade::factory()->create([
        'journal_id' => $account->journal_id,
        'account_id' => $account->id,
        'net_pnl'    => $net,
        'entry_at'   => $at,
        'exit_at'    => $at->copy()->addMinutes(5),
    ]);
}

function statsComponent(User $user, Journal $journal, array $params = [])
{
    return Livewire::actingAs($user)
        ->withQueryParams($params)
        ->test('journal.statistics', ['journal' => $journal]);
}

// ---------------------------------------------------------------------------
// Page load & navigation
// ---------------------------------------------------------------------------

test('statistics page loads for an authenticated user', function () {
    [$user] = statsPageUser();

    $this->actingAs($user)
        ->get('/journal/statistics')
        ->assertOk()
        ->assertSeeLivewire('journal.statistics');
});

test('statistics page redirects unauthenticated users to login', function () {
    $this->get('/journal/statistics')->assertRedirect('/login');
});

test('navigation links to the statistics page', function () {
    [$user] = statsPageUser();

    $this->actingAs($user)
        ->get('/journal')
        ->assertSee(route('journal.statistics'));
});

test('the page renders every section when there is data', function () {
    [$user, $journal] = statsPageUser();
    $account = statsAccount($journal, ['starting_balance' => 50000]);
    $trade = statsPageTrade($account, 250, '2026-03-02 14:00:00');
    statsPageTrade($account, -100, '2026-03-03 15:00:00');
    TradeLeg::factory()->create(['trade_id' => $trade->id, 'runner' => true]);

    statsComponent($user, $journal)
        ->assertSee('P&amp;L Curve', false)
        ->assertSee('Equity Curve')
        ->assertSee('Daily P&amp;L', false)
        ->assertSee('By Trade Type')
        ->assertSee('By Exit Reason')
        ->assertSee('MAE / MFE')
        ->assertSee('Runners');
});

test('the page renders an empty state with no trades', function () {
    [$user, $journal] = statsPageUser();

    statsComponent($user, $journal)->assertSee('No trades match');
});

// ---------------------------------------------------------------------------
// Scope: journal, accounts, dates
// ---------------------------------------------------------------------------

test('statistics only include trades from the user own journal', function () {
    [$user, $journal] = statsPageUser();
    statsPageTrade(statsAccount($journal), 100, '2026-03-02 14:00:00');

    [, $other] = statsPageUser();
    statsPageTrade(statsAccount($other), 999, '2026-03-02 14:00:00');

    $s = statsComponent($user, $journal)->instance()->stats->summary();

    expect($s['total'])->toBe(1)->and($s['net_pnl'])->toBe(100.0);
});

test('null selectedAccountIds includes every account', function () {
    [$user, $journal] = statsPageUser();
    statsPageTrade(statsAccount($journal), 100, '2026-03-02 14:00:00');
    statsPageTrade(statsAccount($journal), 50, '2026-03-02 15:00:00');

    $s = statsComponent($user, $journal)->set('selectedAccountIds', null)->instance()->stats->summary();

    expect($s['total'])->toBe(2);
});

test('empty selectedAccountIds includes no trades', function () {
    [$user, $journal] = statsPageUser();
    statsPageTrade(statsAccount($journal), 100, '2026-03-02 14:00:00');

    $s = statsComponent($user, $journal)->set('selectedAccountIds', [])->instance()->stats->summary();

    expect($s['total'])->toBe(0);
});

test('specific selectedAccountIds narrows statistics to those accounts', function () {
    [$user, $journal] = statsPageUser();
    $a = statsAccount($journal);
    $b = statsAccount($journal);
    statsPageTrade($a, 100, '2026-03-02 14:00:00');
    statsPageTrade($b, -40, '2026-03-02 15:00:00');

    $s = statsComponent($user, $journal)->set('selectedAccountIds', [$b->id])->instance()->stats->summary();

    expect($s['total'])->toBe(1)->and($s['net_pnl'])->toBe(-40.0);
});

test('the accounts query-string selection is applied on load', function () {
    [$user, $journal] = statsPageUser();
    $a = statsAccount($journal);
    $b = statsAccount($journal);
    statsPageTrade($a, 100, '2026-03-02 14:00:00');
    statsPageTrade($b, -40, '2026-03-02 15:00:00');

    $s = statsComponent($user, $journal, ['accounts' => [$a->id]])->instance()->stats->summary();

    expect($s['total'])->toBe(1)->and($s['net_pnl'])->toBe(100.0);
});

test('the date range narrows statistics and defaults to all time', function () {
    [$user, $journal] = statsPageUser();
    $a = statsAccount($journal);
    statsPageTrade($a, 100, '2025-01-15 14:00:00');
    statsPageTrade($a, 50, '2026-03-02 14:00:00');

    $component = statsComponent($user, $journal);
    expect($component->instance()->stats->summary()['total'])->toBe(2);

    $s = $component->set('dateFrom', '2026-03-01')->instance()->stats->summary();
    expect($s['total'])->toBe(1)->and($s['net_pnl'])->toBe(50.0);
});

test('the all-time label shows the earliest trade date', function () {
    [$user, $journal] = statsPageUser();
    $a = statsAccount($journal);
    statsPageTrade($a, 100, '2025-01-15 14:00:00');
    statsPageTrade($a, 50, '2026-03-02 14:00:00');

    statsComponent($user, $journal)->assertSee('Jan 15, 2025');
});

// ---------------------------------------------------------------------------
// Equity curve
// ---------------------------------------------------------------------------

test('equity curve sums starting balances and cash flows of the selected accounts only', function () {
    [$user, $journal] = statsPageUser();
    $a = statsAccount($journal, ['starting_balance' => 50000]);
    $b = statsAccount($journal, ['starting_balance' => 25000]);

    statsPageTrade($a, 300, '2026-03-02 14:00:00');
    statsPageTrade($a, 200, '2026-03-04 14:00:00');
    statsPageTrade($b, 999, '2026-03-02 14:00:00');
    AccountTransaction::factory()->create(['account_id' => $a->id, 'type' => TransactionType::Deposit, 'amount' => 1000, 'occurred_at' => '2026-03-03']);
    AccountTransaction::factory()->create(['account_id' => $a->id, 'type' => TransactionType::Withdrawal, 'amount' => 2000, 'occurred_at' => '2026-03-04']);
    AccountTransaction::factory()->create(['account_id' => $b->id, 'type' => TransactionType::Deposit, 'amount' => 7777, 'occurred_at' => '2026-03-03']);

    $component = statsComponent($user, $journal)->set('selectedAccountIds', [$a->id]);

    expect($component->instance()->equity['points']->pluck('balance', 'date')->all())->toBe([
        '2026-03-02' => 50300.0,
        '2026-03-03' => 51300.0,   // deposit day with no trades still plotted
        '2026-03-04' => 49500.0,   // -2000 withdrawal, +200 P&L
    ]);

    // A withdrawal lowers the balance but is not a trading drawdown.
    expect($component->instance()->stats->summary()['max_drawdown'])->toBe(0.0);
});

test('equity curve with both accounts selected sums them rather than averaging', function () {
    [$user, $journal] = statsPageUser();
    $a = statsAccount($journal, ['starting_balance' => 50000]);
    $b = statsAccount($journal, ['starting_balance' => 25000]);
    statsPageTrade($a, 300, '2026-03-02 14:00:00');
    statsPageTrade($b, -100, '2026-03-02 15:00:00');

    $equity = statsComponent($user, $journal)->instance()->equity;

    expect($equity['points']->pluck('balance', 'date')->all())->toBe(['2026-03-02' => 75200.0]);
});

test('equity curve opens at the real balance when a start date is set', function () {
    [$user, $journal] = statsPageUser();
    $a = statsAccount($journal, ['starting_balance' => 50000]);

    // Before the range: +400 P&L and a 1,000 withdrawal → opening 49,400.
    statsPageTrade($a, 400, '2026-02-10 14:00:00');
    AccountTransaction::factory()->create(['account_id' => $a->id, 'type' => TransactionType::Withdrawal, 'amount' => 1000, 'occurred_at' => '2026-02-20']);
    statsPageTrade($a, 100, '2026-03-02 14:00:00');

    $equity = statsComponent($user, $journal)->set('dateFrom', '2026-03-01')->instance()->equity;

    expect($equity['opening'])->toBe(49400.0)
        ->and($equity['points']->pluck('balance', 'date')->all())->toBe(['2026-03-02' => 49500.0]);
});

test('equity curve flags accounts with no starting balance', function () {
    [$user, $journal] = statsPageUser();
    statsAccount($journal, ['starting_balance' => null]);
    statsAccount($journal, ['starting_balance' => 10000]);

    expect(statsComponent($user, $journal)->instance()->equity['missing_balance'])->toBe(1);
});
