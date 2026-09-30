<?php

use App\Enums\AccountType;
use App\Enums\ScreenshotSource;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\Journal;
use App\Models\Trade;
use App\Models\TradeExecution;
use App\Models\TradeNote;
use App\Models\TradeScreenshot;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(LazilyRefreshDatabase::class);

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function accountsUser(): array
{
    $user    = User::factory()->create();
    $journal = $user->journal;
    return [$user, $journal];
}

// ---------------------------------------------------------------------------
// Page load
// ---------------------------------------------------------------------------

test('accounts page loads for authenticated user', function () {
    [$user] = accountsUser();

    $this->actingAs($user)
        ->get('/accounts')
        ->assertStatus(200);
});

test('accounts page redirects unauthenticated users', function () {
    $this->get('/accounts')->assertRedirect('/login');
});

// ---------------------------------------------------------------------------
// Create
// ---------------------------------------------------------------------------

test('can create an account', function () {
    [$user, $journal] = accountsUser();

    Livewire::actingAs($user)
        ->test('journal.accounts', ['journal' => $journal])
        ->call('startCreate')
        ->set('name', 'Apex-12345')
        ->set('accountType', AccountType::Funded->value)
        ->set('startingBalance', '50000')
        ->call('save');

    expect(Account::where('journal_id', $journal->id)->count())->toBe(1);
    $account = Account::where('journal_id', $journal->id)->first();
    expect($account->name)->toBe('Apex-12345')
        ->and($account->account_type)->toBe(AccountType::Funded)
        ->and((float) $account->starting_balance)->toBe(50000.0);
});

test('can create an account without touching the account type dropdown', function () {
    [$user, $journal] = accountsUser();

    // Regression: startCreate()/resetForm() must leave accountType on a value
    // that actually exists in the AccountType enum, since a real user who
    // doesn't interact with the dropdown never fires a wire:model change —
    // whatever the property defaults to is what gets validated on save.
    Livewire::actingAs($user)
        ->test('journal.accounts', ['journal' => $journal])
        ->call('startCreate')
        ->set('name', 'Apex-99999')
        ->call('save')
        ->assertHasNoErrors();

    expect(Account::where('journal_id', $journal->id)->where('name', 'Apex-99999')->first())
        ->not->toBeNull();
});

test('creating an account requires a name', function () {
    [$user, $journal] = accountsUser();

    Livewire::actingAs($user)
        ->test('journal.accounts', ['journal' => $journal])
        ->call('startCreate')
        ->set('name', '')
        ->call('save')
        ->assertHasErrors(['name' => 'required']);

    expect(Account::where('journal_id', $journal->id)->count())->toBe(0);
});

test('cannot create two accounts with the same name in the same journal', function () {
    [$user, $journal] = accountsUser();
    Account::factory()->create(['journal_id' => $journal->id, 'name' => 'Apex-12345']);

    Livewire::actingAs($user)
        ->test('journal.accounts', ['journal' => $journal])
        ->call('startCreate')
        ->set('name', 'Apex-12345')
        ->set('accountType', AccountType::Sim->value)
        ->call('save')
        ->assertHasErrors(['name']);

    expect(Account::where('journal_id', $journal->id)->count())->toBe(1);
});

test('same account name is allowed in a different journal', function () {
    [$userA, $journalA] = accountsUser();
    [$userB, $journalB] = accountsUser();
    Account::factory()->create(['journal_id' => $journalA->id, 'name' => 'Apex-12345']);

    Livewire::actingAs($userB)
        ->test('journal.accounts', ['journal' => $journalB])
        ->call('startCreate')
        ->set('name', 'Apex-12345')
        ->set('accountType', AccountType::Funded->value)
        ->call('save');

    expect(Account::where('journal_id', $journalB->id)->count())->toBe(1);
});

test('starting balance is optional', function () {
    [$user, $journal] = accountsUser();

    Livewire::actingAs($user)
        ->test('journal.accounts', ['journal' => $journal])
        ->call('startCreate')
        ->set('name', 'Apex-NoBalance')
        ->set('accountType', AccountType::Eval->value)
        ->set('startingBalance', '')
        ->call('save');

    expect(Account::where('journal_id', $journal->id)->first()->starting_balance)->toBeNull();
});

// ---------------------------------------------------------------------------
// Edit
// ---------------------------------------------------------------------------

test('can edit an account name and type', function () {
    [$user, $journal] = accountsUser();
    $account = Account::factory()->create([
        'journal_id'   => $journal->id,
        'name'         => 'Old Name',
        'account_type' => AccountType::Sim,
    ]);

    Livewire::actingAs($user)
        ->test('journal.accounts', ['journal' => $journal])
        ->call('startEdit', $account->id)
        ->set('name', 'New Name')
        ->set('accountType', AccountType::Funded->value)
        ->call('save');

    $fresh = $account->fresh();
    expect($fresh->name)->toBe('New Name')
        ->and($fresh->account_type)->toBe(AccountType::Funded);
});

test('editing to a duplicate name within the same journal fails', function () {
    [$user, $journal] = accountsUser();
    Account::factory()->create(['journal_id' => $journal->id, 'name' => 'Account A']);
    $b = Account::factory()->create(['journal_id' => $journal->id, 'name' => 'Account B']);

    Livewire::actingAs($user)
        ->test('journal.accounts', ['journal' => $journal])
        ->call('startEdit', $b->id)
        ->set('name', 'Account A')
        ->call('save')
        ->assertHasErrors(['name']);

    expect($b->fresh()->name)->toBe('Account B');
});

test('editing to the same name is allowed', function () {
    [$user, $journal] = accountsUser();
    $account = Account::factory()->create(['journal_id' => $journal->id, 'name' => 'Apex-12345']);

    Livewire::actingAs($user)
        ->test('journal.accounts', ['journal' => $journal])
        ->call('startEdit', $account->id)
        ->set('name', 'Apex-12345')
        ->set('accountType', AccountType::Eval->value)
        ->call('save')
        ->assertHasNoErrors();

    expect($account->fresh()->account_type)->toBe(AccountType::Eval);
});

// ---------------------------------------------------------------------------
// Delete
// ---------------------------------------------------------------------------

test('can delete an account with no trades', function () {
    [$user, $journal] = accountsUser();
    $account = Account::factory()->create(['journal_id' => $journal->id]);

    Livewire::actingAs($user)
        ->test('journal.accounts', ['journal' => $journal])
        ->call('delete', $account->id);

    expect(Account::find($account->id))->toBeNull();
});

test('cannot delete an account that has trades', function () {
    [$user, $journal] = accountsUser();
    $account = Account::factory()->create(['journal_id' => $journal->id]);
    Trade::factory()->create([
        'journal_id' => $journal->id,
        'account_id' => $account->id,
        'entry_at'   => now()->subHour(),
        'exit_at'    => now()->subMinutes(30),
    ]);

    Livewire::actingAs($user)
        ->test('journal.accounts', ['journal' => $journal])
        ->call('delete', $account->id)
        ->assertHasErrors(['delete']);

    expect(Account::find($account->id))->not->toBeNull();
});

// ---------------------------------------------------------------------------
// Clear trades
// ---------------------------------------------------------------------------

function tradeInAccount(Account $account): Trade
{
    return Trade::factory()->create([
        'journal_id' => $account->journal_id,
        'account_id' => $account->id,
        'entry_at'   => now()->subHour(),
        'exit_at'    => now()->subMinutes(30),
    ]);
}

test('clearTrades removes all trades, their data, and screenshot files but keeps the account', function () {
    Storage::fake('local');
    [$user, $journal] = accountsUser();
    $account = Account::factory()->create(['journal_id' => $journal->id, 'starting_balance' => '50000.00']);
    $tradeA  = tradeInAccount($account);
    $tradeB  = tradeInAccount($account);

    TradeExecution::factory()->create(['trade_id' => $tradeA->id]);
    TradeNote::factory()->create(['trade_id' => $tradeA->id, 'created_by' => $user->id]);

    $path = "trade-screenshots/{$journal->id}/{$tradeA->uuid}.png";
    Storage::disk('local')->put($path, 'fake-image-data');
    TradeScreenshot::factory()->create([
        'trade_id'  => $tradeA->id,
        'disk'      => 'local',
        'path'      => $path,
        'mime_type' => 'image/png',
        'bytes'     => 15,
        'source'    => ScreenshotSource::ManualUpload,
    ]);

    Livewire::actingAs($user)
        ->test('journal.accounts', ['journal' => $journal])
        ->call('clearTrades', $account->id)
        ->assertHasNoErrors();

    $fresh = Account::find($account->id);
    expect($fresh)->not->toBeNull()
        ->and($fresh->name)->toBe($account->name)
        ->and((float) $fresh->starting_balance)->toBe(50000.0)
        ->and(Trade::count())->toBe(0)
        ->and(TradeExecution::count())->toBe(0)
        ->and(TradeNote::count())->toBe(0)
        ->and(TradeScreenshot::count())->toBe(0);
    Storage::disk('local')->assertMissing($path);
});

test('clearTrades leaves other accounts\' trades untouched', function () {
    [$user, $journal] = accountsUser();
    $cleared = Account::factory()->create(['journal_id' => $journal->id]);
    $kept    = Account::factory()->create(['journal_id' => $journal->id]);
    tradeInAccount($cleared);
    $keptTrade = tradeInAccount($kept);

    Livewire::actingAs($user)
        ->test('journal.accounts', ['journal' => $journal])
        ->call('clearTrades', $cleared->id);

    expect(Trade::where('account_id', $cleared->id)->count())->toBe(0)
        ->and(Trade::find($keptTrade->id))->not->toBeNull();
});

test('account can be deleted after its trades are cleared', function () {
    [$user, $journal] = accountsUser();
    $account = Account::factory()->create(['journal_id' => $journal->id]);
    tradeInAccount($account);

    Livewire::actingAs($user)
        ->test('journal.accounts', ['journal' => $journal])
        ->call('clearTrades', $account->id)
        ->call('delete', $account->id)
        ->assertHasNoErrors();

    expect(Account::find($account->id))->toBeNull();
});

test('Clear and Delete ask for confirmation in a popup before running', function () {
    [$user, $journal] = accountsUser();
    $account = Account::factory()->create(['journal_id' => $journal->id, 'name' => 'Apex-12345']);
    tradeInAccount($account);
    tradeInAccount($account);

    // wire:confirm shows a browser confirm dialog and only sends the call if
    // the trader accepts — the same pattern as deleting a trade.
    Livewire::actingAs($user)
        ->test('journal.accounts', ['journal' => $journal])
        ->assertSeeHtml("wire:click=\"clearTrades({$account->id})\"")
        ->assertSeeHtml('wire:confirm="Clear all 2 trade(s) from &quot;Apex-12345&quot;? All trades and images will be lost. This cannot be undone."')
        ->assertSeeHtml("wire:click=\"delete({$account->id})\"")
        ->assertSeeHtml('wire:confirm="Delete account &quot;Apex-12345&quot;? This cannot be undone."');
});

test('Clear button only shows for accounts with trades', function () {
    [$user, $journal] = accountsUser();
    $account = Account::factory()->create(['journal_id' => $journal->id]);

    $component = Livewire::actingAs($user)
        ->test('journal.accounts', ['journal' => $journal])
        ->assertDontSeeHtml("clearTrades({$account->id})");

    tradeInAccount($account);

    $component->call('$refresh')
        ->assertSeeHtml("clearTrades({$account->id})");
});

test('cannot clear trades from an account in another journal', function () {
    [$user, $journal]  = accountsUser();
    [, $otherJournal]  = accountsUser();
    $otherAccount = Account::factory()->create(['journal_id' => $otherJournal->id]);
    $otherTrade   = tradeInAccount($otherAccount);

    try {
        Livewire::actingAs($user)
            ->test('journal.accounts', ['journal' => $journal])
            ->call('clearTrades', $otherAccount->id);
        $this->fail('Expected ModelNotFoundException');
    } catch (ModelNotFoundException) {
        // expected
    }

    expect(Trade::find($otherTrade->id))->not->toBeNull();
});

// ---------------------------------------------------------------------------
// Balance calculation
// ---------------------------------------------------------------------------

test('current balance shown is starting balance plus net pnl', function () {
    [$user, $journal] = accountsUser();
    $account = Account::factory()->create([
        'journal_id'      => $journal->id,
        'starting_balance'=> '50000.00',
        'account_type'    => AccountType::Funded,
    ]);
    Trade::factory()->create([
        'journal_id' => $journal->id,
        'account_id' => $account->id,
        'net_pnl'    => '500.00',
        'entry_at'   => now()->subHour(),
        'exit_at'    => now()->subMinutes(30),
    ]);
    Trade::factory()->create([
        'journal_id' => $journal->id,
        'account_id' => $account->id,
        'net_pnl'    => '-200.00',
        'entry_at'   => now()->subHour(),
        'exit_at'    => now()->subMinutes(30),
    ]);

    $component = Livewire::actingAs($user)
        ->test('journal.accounts', ['journal' => $journal]);

    $listed = $component->get('accounts')->first();
    expect((float) $listed->starting_balance + (float) ($listed->trades_sum_net_pnl ?? 0))
        ->toBe(50300.0);
});

function fundedAccountWithPnl(Journal $journal, string $startingBalance = '50000.00', string $netPnl = '300.00'): Account
{
    $account = Account::factory()->create([
        'journal_id'       => $journal->id,
        'starting_balance' => $startingBalance,
        'account_type'     => AccountType::Funded,
    ]);
    Trade::factory()->create([
        'journal_id' => $journal->id,
        'account_id' => $account->id,
        'net_pnl'    => $netPnl,
        'entry_at'   => now()->subHour(),
        'exit_at'    => now()->subMinutes(30),
    ]);

    return $account;
}

test('current balance includes deposits', function () {
    [$user, $journal] = accountsUser();
    $account = fundedAccountWithPnl($journal);
    AccountTransaction::factory()->create(['account_id' => $account->id, 'type' => TransactionType::Deposit, 'amount' => '1000.00']);
    AccountTransaction::factory()->create(['account_id' => $account->id, 'type' => TransactionType::Deposit, 'amount' => '250.50']);

    $component = Livewire::actingAs($user)
        ->test('journal.accounts', ['journal' => $journal]);

    $listed = $component->get('accounts')->first();
    expect($component->instance()->balanceFor($listed))->toBe(51550.5);
    $component->assertSee('$51,550.50');
});

test('current balance is net of deposits and withdrawals', function () {
    [$user, $journal] = accountsUser();
    $account = fundedAccountWithPnl($journal);
    AccountTransaction::factory()->create(['account_id' => $account->id, 'type' => TransactionType::Deposit, 'amount' => '1000.00']);
    AccountTransaction::factory()->create(['account_id' => $account->id, 'type' => TransactionType::Withdrawal, 'amount' => '2500.00']);

    $component = Livewire::actingAs($user)
        ->test('journal.accounts', ['journal' => $journal]);

    $listed = $component->get('accounts')->first();
    expect($component->instance()->balanceFor($listed))->toBe(48800.0);
    $component->assertSee('$48,800.00');
});

test('adding a transaction persists it on the right account and keeps the ledger open', function () {
    [$user, $journal] = accountsUser();
    $other   = fundedAccountWithPnl($journal);
    $account = fundedAccountWithPnl($journal);

    Livewire::actingAs($user)
        ->test('journal.accounts', ['journal' => $journal])
        ->call('toggleTransactions', $account->id)
        ->set('txType', TransactionType::Deposit->value)
        ->set('txAmount', '1500')
        ->set('txDate', '2026-09-15')
        ->call('addTransaction')
        ->assertHasNoErrors()
        ->assertSet('expandedAccountId', $account->id)
        ->assertSet('txAmount', '')
        ->assertSee('Sep 15, 2026');

    expect(AccountTransaction::where('account_id', $other->id)->count())->toBe(0);
    $tx = AccountTransaction::where('account_id', $account->id)->sole();
    expect($tx->type)->toBe(TransactionType::Deposit)
        ->and((float) $tx->amount)->toBe(1500.0)
        ->and($tx->occurred_at->toDateString())->toBe('2026-09-15');
});

test('a withdrawal up to the current balance is allowed', function () {
    [$user, $journal] = accountsUser();
    $account = fundedAccountWithPnl($journal, '1000.00', '200.00');

    Livewire::actingAs($user)
        ->test('journal.accounts', ['journal' => $journal])
        ->call('toggleTransactions', $account->id)
        ->set('txType', TransactionType::Withdrawal->value)
        ->set('txAmount', '1200.00')
        ->call('addTransaction')
        ->assertHasNoErrors();

    expect(AccountTransaction::where('account_id', $account->id)->count())->toBe(1);
});

test('a withdrawal exceeding the current balance is rejected', function () {
    [$user, $journal] = accountsUser();
    $account = fundedAccountWithPnl($journal, '1000.00', '200.00');
    AccountTransaction::factory()->create(['account_id' => $account->id, 'type' => TransactionType::Withdrawal, 'amount' => '500.00']);

    Livewire::actingAs($user)
        ->test('journal.accounts', ['journal' => $journal])
        ->call('toggleTransactions', $account->id)
        ->set('txType', TransactionType::Withdrawal->value)
        ->set('txAmount', '700.01')
        ->call('addTransaction')
        ->assertHasErrors(['txAmount']);

    expect(AccountTransaction::where('account_id', $account->id)->count())->toBe(1);
});

test('transaction amount must be positive and date is required', function () {
    [$user, $journal] = accountsUser();
    $account = fundedAccountWithPnl($journal);

    Livewire::actingAs($user)
        ->test('journal.accounts', ['journal' => $journal])
        ->call('toggleTransactions', $account->id)
        ->set('txAmount', '0')
        ->set('txDate', '')
        ->call('addTransaction')
        ->assertHasErrors(['txAmount', 'txDate']);

    expect(AccountTransaction::count())->toBe(0);
});

test('Transactions button and ledger only appear for funded accounts', function () {
    [$user, $journal] = accountsUser();
    $funded = Account::factory()->create(['journal_id' => $journal->id, 'account_type' => AccountType::Funded]);
    $sim    = Account::factory()->create(['journal_id' => $journal->id, 'account_type' => AccountType::Sim]);
    $eval   = Account::factory()->create(['journal_id' => $journal->id, 'account_type' => AccountType::Eval]);

    $component = Livewire::actingAs($user)
        ->test('journal.accounts', ['journal' => $journal])
        ->assertSeeHtml("toggleTransactions({$funded->id})")
        ->assertDontSeeHtml("toggleTransactions({$sim->id})")
        ->assertDontSeeHtml("toggleTransactions({$eval->id})")
        ->assertDontSee('Deposits & Withdrawals');

    $component->call('toggleTransactions', $funded->id)
        ->assertSee('Deposits & Withdrawals');

    // Non-funded accounts can't be opened (or written to) by calling the
    // action directly either.
    expect(fn () => $component->call('toggleTransactions', $sim->id))
        ->toThrow(ModelNotFoundException::class);
    expect(fn () => $component->set('expandedAccountId', $eval->id)
        ->set('txAmount', '100')
        ->call('addTransaction'))
        ->toThrow(ModelNotFoundException::class);
    expect(AccountTransaction::count())->toBe(0);
});

test('deleting an account removes its transactions', function () {
    [$user, $journal] = accountsUser();
    $account = Account::factory()->create(['journal_id' => $journal->id, 'account_type' => AccountType::Funded]);
    AccountTransaction::factory()->count(2)->create(['account_id' => $account->id]);

    Livewire::actingAs($user)
        ->test('journal.accounts', ['journal' => $journal])
        ->call('delete', $account->id)
        ->assertHasNoErrors();

    expect(AccountTransaction::count())->toBe(0);
});
