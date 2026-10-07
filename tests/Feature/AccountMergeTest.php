<?php

use App\Enums\AccountType;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\Trade;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;

uses(LazilyRefreshDatabase::class);

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * #99: the trader created "TEST-ACCT-001" by hand; NT8 sent trades as
 * "TEST-ACCT-001-04", so the journal auto-created that one too.
 */
function mergePair(): array
{
    $user = User::factory()->create();
    $journal = $user->journal;

    $handMade = Account::factory()->for($journal)->create([
        'name' => 'TEST-ACCT-001',
        'account_type' => AccountType::Eval,
        'starting_balance' => 50000,
        'connection' => 'Rithmic',
        'timezone' => null,
    ]);
    $auto = Account::factory()->for($journal)->create([
        'name' => 'TEST-ACCT-001-04',
        'account_type' => AccountType::Funded,
        'starting_balance' => null,
        'connection' => 'Broker Rithmic',
        'timezone' => null,
    ]);

    return [$user, $journal, $handMade, $auto];
}

function mergeTrade($journal, Account $account): Trade
{
    return Trade::factory()->create([
        'journal_id' => $journal->id,
        'account_id' => $account->id,
        'entry_at' => now()->subHour(),
        'exit_at' => now()->subMinutes(30),
    ]);
}

// ---------------------------------------------------------------------------
// Merging
// ---------------------------------------------------------------------------

test('merging moves trades and transactions to the target and deletes the source', function () {
    [$user, $journal, $handMade, $auto] = mergePair();
    $movedTrade = mergeTrade($journal, $handMade);
    $keptTrade = mergeTrade($journal, $auto);
    $tx = AccountTransaction::factory()->for($handMade)->create(['type' => TransactionType::Deposit, 'amount' => 250]);

    Livewire::actingAs($user)
        ->test('journal.accounts', ['journal' => $journal])
        ->call('startMerge', $handMade->id)
        ->set('mergeTargetId', (string) $auto->id)
        ->call('merge')
        ->assertHasNoErrors()
        ->assertSet('mergingId', null);

    expect(Account::find($handMade->id))->toBeNull()
        ->and($movedTrade->refresh()->account_id)->toBe($auto->id)
        ->and($keptTrade->refresh()->account_id)->toBe($auto->id)
        ->and($tx->refresh()->account_id)->toBe($auto->id);
});

test('the target keeps its name and takes the merged account type and starting balance', function () {
    [$user, $journal, $handMade, $auto] = mergePair();

    Livewire::actingAs($user)
        ->test('journal.accounts', ['journal' => $journal])
        ->call('startMerge', $handMade->id)
        ->set('mergeTargetId', (string) $auto->id)
        ->call('merge');

    $auto->refresh();
    expect($auto->name)->toBe('TEST-ACCT-001-04')
        ->and($auto->account_type)->toBe(AccountType::Eval)
        ->and((float) $auto->starting_balance)->toBe(50000.0)
        ->and($auto->connection)->toBe('Broker Rithmic');
});

test('target keeps its own starting balance and gets a connection when the source has none of them', function () {
    [$user, $journal, $handMade, $auto] = mergePair();
    $handMade->update(['starting_balance' => null, 'connection' => 'Rithmic']);
    $auto->update(['starting_balance' => 25000, 'connection' => null, 'timezone' => null]);
    $handMade->update(['timezone' => 'America/Chicago']);

    Livewire::actingAs($user)
        ->test('journal.accounts', ['journal' => $journal])
        ->call('startMerge', $handMade->id)
        ->set('mergeTargetId', (string) $auto->id)
        ->call('merge');

    $auto->refresh();
    expect((float) $auto->starting_balance)->toBe(25000.0)
        ->and($auto->connection)->toBe('Rithmic')
        ->and($auto->timezone)->toBe('America/Chicago');
});

test('trades sent after the merge still land on the target account', function () {
    [$user, $journal, $handMade, $auto] = mergePair();

    Livewire::actingAs($user)
        ->test('journal.accounts', ['journal' => $journal])
        ->call('startMerge', $handMade->id)
        ->set('mergeTargetId', (string) $auto->id)
        ->call('merge');

    expect($journal->accounts()->pluck('name')->all())->toBe(['TEST-ACCT-001-04']);
});

test('the merge panel lists only the other accounts in the journal', function () {
    [$user, $journal, $handMade, $auto] = mergePair();
    $other = User::factory()->create();
    Account::factory()->for($other->journal)->create(['name' => 'OTHER-USER-ACCT']);

    Livewire::actingAs($user)
        ->test('journal.accounts', ['journal' => $journal])
        ->call('startMerge', $handMade->id)
        ->assertSeeHtml('value="'.$auto->id.'"')
        ->assertDontSeeHtml('value="'.$handMade->id.'"')
        ->assertDontSee('OTHER-USER-ACCT');
});

test('cancelMerge closes the panel without changing anything', function () {
    [$user, $journal, $handMade, $auto] = mergePair();

    Livewire::actingAs($user)
        ->test('journal.accounts', ['journal' => $journal])
        ->call('startMerge', $handMade->id)
        ->set('mergeTargetId', (string) $auto->id)
        ->call('cancelMerge')
        ->assertSet('mergingId', null)
        ->assertSet('mergeTargetId', '');

    expect(Account::find($handMade->id))->not->toBeNull();
});

// ---------------------------------------------------------------------------
// Rejected merges
// ---------------------------------------------------------------------------

test('merge requires a target', function () {
    [$user, $journal, $handMade] = mergePair();

    Livewire::actingAs($user)
        ->test('journal.accounts', ['journal' => $journal])
        ->call('startMerge', $handMade->id)
        ->call('merge')
        ->assertHasErrors(['mergeTargetId']);

    expect(Account::find($handMade->id))->not->toBeNull();
});

test('cannot merge an account into itself', function () {
    [$user, $journal, $handMade] = mergePair();

    Livewire::actingAs($user)
        ->test('journal.accounts', ['journal' => $journal])
        ->call('startMerge', $handMade->id)
        ->set('mergeTargetId', (string) $handMade->id)
        ->call('merge')
        ->assertHasErrors(['mergeTargetId']);

    expect(Account::find($handMade->id))->not->toBeNull();
});

test('cannot merge into another user\'s account', function () {
    [$user, $journal, $handMade] = mergePair();
    $other = User::factory()->create();
    $otherAccount = Account::factory()->for($other->journal)->create();
    $trade = mergeTrade($journal, $handMade);

    Livewire::actingAs($user)
        ->test('journal.accounts', ['journal' => $journal])
        ->call('startMerge', $handMade->id)
        ->set('mergeTargetId', (string) $otherAccount->id)
        ->call('merge')
        ->assertHasErrors(['mergeTargetId']);

    expect(Account::find($handMade->id))->not->toBeNull()
        ->and($trade->refresh()->account_id)->toBe($handMade->id);
});

test('cannot start merging another user\'s account', function () {
    [$user, $journal] = mergePair();
    $other = User::factory()->create();
    $otherAccount = Account::factory()->for($other->journal)->create();

    expect(fn () => Livewire::actingAs($user)
        ->test('journal.accounts', ['journal' => $journal])
        ->call('startMerge', $otherAccount->id))
        ->toThrow(ModelNotFoundException::class);

    expect(Account::find($otherAccount->id))->not->toBeNull();
});

test('a source account deleted after the panel opened fails cleanly', function () {
    [$user, $journal, $handMade, $auto] = mergePair();

    $component = Livewire::actingAs($user)
        ->test('journal.accounts', ['journal' => $journal])
        ->call('startMerge', $handMade->id)
        ->set('mergeTargetId', (string) $auto->id);

    $handMade->delete();

    expect(fn () => $component->call('merge'))
        ->toThrow(ModelNotFoundException::class);

    expect(Account::find($auto->id))->not->toBeNull();
});

// ---------------------------------------------------------------------------
// Page wording (#99)
// ---------------------------------------------------------------------------

test('the accounts page tells AddOn users their accounts appear on the first trade', function () {
    [$user] = mergePair();

    $this->actingAs($user)
        ->get('/accounts')
        ->assertOk()
        ->assertSee('appear here on their first trade')
        ->assertSee('exact NinjaTrader account name')
        ->assertDontSee('Create accounts here before importing trades from NinjaTrader.');
});
