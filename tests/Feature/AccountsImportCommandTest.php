<?php

use App\Enums\AccountType;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\Trade;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function importCsv(string $contents): string
{
    $path = tempnam(sys_get_temp_dir(), 'acct-import-');
    file_put_contents($path, $contents);

    return $path;
}

const IMPORT_HEADER = "name,type,starting_balance,connection\n";

// ---------------------------------------------------------------------------
// Creating accounts
// ---------------------------------------------------------------------------

test('creates accounts in the user journal', function () {
    $user = User::factory()->create();
    $file = importCsv(IMPORT_HEADER
        ."TEST-ACCT-001,funded,50000.25,Rithmic\n"
        ."TEST-ACCT-002,eval,,\n");

    $this->artisan('accounts:import', ['email' => $user->email, 'file' => $file])
        ->assertSuccessful();

    $accounts = $user->journal->accounts()->orderBy('name')->get();
    expect($accounts)->toHaveCount(2);

    expect($accounts[0]->name)->toBe('TEST-ACCT-001')
        ->and($accounts[0]->account_type)->toBe(AccountType::Funded)
        ->and((float) $accounts[0]->starting_balance)->toBe(50000.25)
        ->and($accounts[0]->connection)->toBe('Rithmic')
        ->and($accounts[0]->timezone)->toBeNull();

    expect($accounts[1]->account_type)->toBe(AccountType::Eval)
        ->and($accounts[1]->starting_balance)->toBeNull()
        ->and($accounts[1]->connection)->toBeNull();
});

test('type is case-insensitive and fields are trimmed', function () {
    $user = User::factory()->create();
    $file = importCsv(IMPORT_HEADER." TEST-ACCT-001 , Funded , 100 , Rithmic \n");

    $this->artisan('accounts:import', ['email' => $user->email, 'file' => $file])
        ->assertSuccessful();

    $account = $user->journal->accounts()->sole();
    expect($account->name)->toBe('TEST-ACCT-001')
        ->and($account->account_type)->toBe(AccountType::Funded)
        ->and($account->connection)->toBe('Rithmic');
});

test('does not touch other users journals', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    Account::factory()->for($other->journal)->create(['name' => 'TEST-ACCT-001', 'starting_balance' => 10]);

    $file = importCsv(IMPORT_HEADER."TEST-ACCT-001,eval,500,\n");

    $this->artisan('accounts:import', ['email' => $user->email, 'file' => $file])
        ->assertSuccessful();

    expect((float) $other->journal->accounts()->sole()->starting_balance)->toBe(10.0);
    expect($user->journal->accounts()->sole()->account_type)->toBe(AccountType::Eval);
});

// ---------------------------------------------------------------------------
// Updating existing accounts
// ---------------------------------------------------------------------------

test('updates an existing account with the same name instead of duplicating it', function () {
    $user = User::factory()->create();
    $existing = Account::factory()->for($user->journal)->create([
        'name' => 'TEST-ACCT-001',
        'account_type' => AccountType::Funded,
        'starting_balance' => null,
        'connection' => null,
        'timezone' => 'America/Chicago',
    ]);

    $file = importCsv(IMPORT_HEADER."TEST-ACCT-001,eval,50000,Rithmic\n");

    $this->artisan('accounts:import', ['email' => $user->email, 'file' => $file])
        ->assertSuccessful();

    $existing->refresh();
    expect($user->journal->accounts()->count())->toBe(1)
        ->and($existing->account_type)->toBe(AccountType::Eval)
        ->and((float) $existing->starting_balance)->toBe(50000.0)
        ->and($existing->connection)->toBe('Rithmic')
        ->and($existing->timezone)->toBe('America/Chicago');
});

// ---------------------------------------------------------------------------
// --current: CSV balance is today's balance
// ---------------------------------------------------------------------------

test('--current backs out journaled pnl and transactions from the starting balance', function () {
    $user = User::factory()->create();
    $journal = $user->journal;
    $account = Account::factory()->for($journal)->create(['name' => 'TEST-ACCT-001', 'starting_balance' => null]);

    Trade::factory()->create(['journal_id' => $journal->id, 'account_id' => $account->id, 'net_pnl' => 1200.50]);
    Trade::factory()->create(['journal_id' => $journal->id, 'account_id' => $account->id, 'net_pnl' => -200.25]);
    AccountTransaction::factory()->for($account)->create(['type' => TransactionType::Deposit, 'amount' => 300]);
    AccountTransaction::factory()->for($account)->create(['type' => TransactionType::Withdrawal, 'amount' => 1000]);

    $file = importCsv(IMPORT_HEADER."TEST-ACCT-001,funded,51000.00,Rithmic\n");

    $this->artisan('accounts:import', ['email' => $user->email, 'file' => $file, '--current' => true])
        ->assertSuccessful();

    // 51000 - (1200.50 - 200.25) - 300 + 1000 = 50699.75
    expect((float) $account->refresh()->starting_balance)->toBe(50699.75);
});

test('--current on a new account uses the balance as given', function () {
    $user = User::factory()->create();
    $file = importCsv(IMPORT_HEADER."TEST-ACCT-001,eval,50000,\n");

    $this->artisan('accounts:import', ['email' => $user->email, 'file' => $file, '--current' => true])
        ->assertSuccessful();

    expect((float) $user->journal->accounts()->sole()->starting_balance)->toBe(50000.0);
});

test('--current fails when the computed starting balance is negative', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user->journal)->create(['name' => 'TEST-ACCT-001']);
    Trade::factory()->create(['journal_id' => $user->journal->id, 'account_id' => $account->id, 'net_pnl' => 5000]);

    $file = importCsv(IMPORT_HEADER."TEST-ACCT-001,funded,1000,\n");

    $this->artisan('accounts:import', ['email' => $user->email, 'file' => $file, '--current' => true])
        ->assertFailed();

    expect($account->refresh()->starting_balance)->toBeNull();
});

test('--current requires a balance', function () {
    $user = User::factory()->create();
    $file = importCsv(IMPORT_HEADER."TEST-ACCT-001,funded,,\n");

    $this->artisan('accounts:import', ['email' => $user->email, 'file' => $file, '--current' => true])
        ->assertFailed();

    expect($user->journal->accounts()->count())->toBe(0);
});

// ---------------------------------------------------------------------------
// --dry-run
// ---------------------------------------------------------------------------

test('--dry-run writes nothing', function () {
    $user = User::factory()->create();
    $existing = Account::factory()->for($user->journal)->create(['name' => 'TEST-ACCT-001', 'starting_balance' => null]);

    $file = importCsv(IMPORT_HEADER."TEST-ACCT-001,eval,500,\nTEST-ACCT-002,funded,100,\n");

    $this->artisan('accounts:import', ['email' => $user->email, 'file' => $file, '--dry-run' => true])
        ->expectsOutputToContain('Dry run')
        ->assertSuccessful();

    expect($user->journal->accounts()->count())->toBe(1)
        ->and($existing->refresh()->starting_balance)->toBeNull();
});

// ---------------------------------------------------------------------------
// Validation: all or nothing
// ---------------------------------------------------------------------------

test('rejects invalid rows and writes nothing', function (string $badRow) {
    $user = User::factory()->create();
    $file = importCsv(IMPORT_HEADER."TEST-ACCT-001,funded,100,\n".$badRow."\n");

    $this->artisan('accounts:import', ['email' => $user->email, 'file' => $file])
        ->assertFailed();

    expect($user->journal->accounts()->count())->toBe(0);
})->with([
    'missing name' => [',funded,100,'],
    'bad type' => ['TEST-ACCT-002,live,100,'],
    'negative balance' => ['TEST-ACCT-002,funded,-5,'],
    'non-numeric' => ['TEST-ACCT-002,funded,abc,'],
    'long name' => [str_repeat('X', 101).',funded,100,'],
    'long connection' => ['TEST-ACCT-002,funded,100,'.str_repeat('X', 101)],
    'duplicate name' => ['TEST-ACCT-001,eval,200,'],
    'wrong col count' => ['TEST-ACCT-002,funded'],
]);

test('rejects a file with the wrong header', function () {
    $user = User::factory()->create();
    $file = importCsv("account,kind,balance\nTEST-ACCT-001,funded,100\n");

    $this->artisan('accounts:import', ['email' => $user->email, 'file' => $file])
        ->assertFailed();

    expect($user->journal->accounts()->count())->toBe(0);
});

test('fails for an unknown user', function () {
    $file = importCsv(IMPORT_HEADER."TEST-ACCT-001,funded,100,\n");

    $this->artisan('accounts:import', ['email' => 'nobody@example.com', 'file' => $file])
        ->assertFailed();

    expect(Account::count())->toBe(0);
});

test('fails for a missing file', function () {
    $user = User::factory()->create();

    $this->artisan('accounts:import', ['email' => $user->email, 'file' => '/nonexistent/accounts.csv'])
        ->assertFailed();
});

test('fails for a file with no rows', function () {
    $user = User::factory()->create();

    $this->artisan('accounts:import', ['email' => $user->email, 'file' => importCsv(IMPORT_HEADER)])
        ->assertFailed();
});
