<?php

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Journal;
use App\Models\Trade;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
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
        ->set('name', 'Test-12345')
        ->set('accountType', AccountType::Funded->value)
        ->set('startingBalance', '50000')
        ->call('save');

    expect(Account::where('journal_id', $journal->id)->count())->toBe(1);
    $account = Account::where('journal_id', $journal->id)->first();
    expect($account->name)->toBe('Test-12345')
        ->and($account->account_type)->toBe(AccountType::Funded)
        ->and((float) $account->starting_balance)->toBe(50000.0);
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
    Account::factory()->create(['journal_id' => $journal->id, 'name' => 'Test-12345']);

    Livewire::actingAs($user)
        ->test('journal.accounts', ['journal' => $journal])
        ->call('startCreate')
        ->set('name', 'Test-12345')
        ->set('accountType', AccountType::Sim->value)
        ->call('save')
        ->assertHasErrors(['name']);

    expect(Account::where('journal_id', $journal->id)->count())->toBe(1);
});

test('same account name is allowed in a different journal', function () {
    [$userA, $journalA] = accountsUser();
    [$userB, $journalB] = accountsUser();
    Account::factory()->create(['journal_id' => $journalA->id, 'name' => 'Test-12345']);

    Livewire::actingAs($userB)
        ->test('journal.accounts', ['journal' => $journalB])
        ->call('startCreate')
        ->set('name', 'Test-12345')
        ->set('accountType', AccountType::Funded->value)
        ->call('save');

    expect(Account::where('journal_id', $journalB->id)->count())->toBe(1);
});

test('starting balance is optional', function () {
    [$user, $journal] = accountsUser();

    Livewire::actingAs($user)
        ->test('journal.accounts', ['journal' => $journal])
        ->call('startCreate')
        ->set('name', 'Test-NoBalance')
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
    $account = Account::factory()->create(['journal_id' => $journal->id, 'name' => 'Test-12345']);

    Livewire::actingAs($user)
        ->test('journal.accounts', ['journal' => $journal])
        ->call('startEdit', $account->id)
        ->set('name', 'Test-12345')
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
        ->call('confirmDelete', $account->id)
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
        ->call('confirmDelete', $account->id)
        ->call('delete', $account->id)
        ->assertHasErrors(['delete']);

    expect(Account::find($account->id))->not->toBeNull();
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
