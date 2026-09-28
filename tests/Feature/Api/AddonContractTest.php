<?php

use App\Models\Account;
use App\Models\Trade;
use App\Models\TradeCopy;
use App\Models\TradeCopyExecution;
use App\Models\TradeExecution;
use App\Models\TradeLeg;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

/*
 * The AddOn's own tests generate these payloads (addon/tests/PayloadFixtures.cs). Posting each one to the real
 * intake endpoint keeps the AddOn and the server in agreement. Regenerate the files with:
 *   PATS_WRITE_FIXTURES=1 dotnet test addon/tests
 */

function addonFixtures(): array
{
    $files = glob(__DIR__ . '/../../Fixtures/addon/*.json');
    sort($files);

    $cases = [];
    foreach ($files as $file) {
        $cases[basename($file, '.json')] = [$file];
    }

    return $cases;
}

function addonJournalToken(): array
{
    $user = User::factory()->create();
    $journal = $user->journal;
    $journal->update(['timezone' => 'America/New_York']);

    return [$journal, $journal->rotateIngestToken()];
}

it('has fixtures to check', function () {
    expect(addonFixtures())->not->toBeEmpty();
});

it('accepts the payload the AddOn produces', function (string $file) {
    [$journal, $token] = addonJournalToken();
    $payload = json_decode(file_get_contents($file), true);

    // Accounts are not registered first: the server creates them.
    test()->postJson('/api/v1/trades', $payload, ['Authorization' => "Bearer {$token}"])
        ->assertStatus(201);

    $trade = Trade::where('source_trade_id', $payload['trade_id'])->firstOrFail();

    expect($trade->journal_id)->toBe($journal->id)
        ->and($trade->account->name)->toBe($payload['account_name'])
        ->and($trade->trade_type->value)->toBe($payload['trade_type'])
        ->and((float) $trade->net_pnl)->toBe((float) $payload['performance']['net_pnl'])
        ->and((float) $trade->gross_pnl)->toBe((float) $payload['performance']['gross_pnl'])
        ->and((int) $trade->quantity)->toBe($payload['quantity'])
        ->and($trade->excursion_complete)->toBe($payload['excursion']['complete'])
        ->and(TradeExecution::where('trade_id', $trade->id)->count())->toBe(count($payload['executions']))
        ->and(TradeLeg::where('trade_id', $trade->id)->count())->toBe(count($payload['legs']))
        ->and(TradeCopy::where('trade_id', $trade->id)->count())->toBe(count($payload['copies']));
})->with(addonFixtures());

it('stores every copy with its own fills', function (string $file) {
    [$journal, $token] = addonJournalToken();
    $payload = json_decode(file_get_contents($file), true);

    test()->postJson('/api/v1/trades', $payload, ['Authorization' => "Bearer {$token}"])->assertStatus(201);

    $trade = Trade::where('source_trade_id', $payload['trade_id'])->firstOrFail();

    foreach ($payload['copies'] as $copyData) {
        $copy = TradeCopy::where('trade_id', $trade->id)
            ->whereHas('account', fn ($q) => $q->where('name', $copyData['account_name']))
            ->firstOrFail();

        expect($copy->status->value)->toBe($copyData['status'])
            ->and($copy->warnings)->toBe($copyData['warnings'])
            ->and((int) $copy->quantity)->toBe($copyData['quantity'])
            ->and(TradeCopyExecution::where('trade_copy_id', $copy->id)->count())->toBe(count($copyData['executions']));

        if ($copyData['performance'] !== null) {
            expect((float) $copy->net_pnl)->toBe((float) $copyData['performance']['net_pnl']);
        }
    }

    expect(Account::where('journal_id', $journal->id)->count())->toBe(1 + count($payload['copies']));
})->with(addonFixtures());

it('treats a re-sent AddOn payload as the same trade', function (string $file) {
    [$journal, $token] = addonJournalToken();
    $payload = json_decode(file_get_contents($file), true);
    $headers = ['Authorization' => "Bearer {$token}", 'Idempotency-Key' => $payload['trade_id']];

    test()->postJson('/api/v1/trades', $payload, $headers)->assertStatus(201);
    test()->postJson('/api/v1/trades', $payload, $headers)->assertStatus(200);

    expect(Trade::count())->toBe(1);
})->with(addonFixtures());
