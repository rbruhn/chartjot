<?php

use App\Models\Account;
use App\Models\Journal;
use App\Models\Trade;
use App\Models\TradeExecution;
use App\Models\TradeLeg;
use App\Models\TradeNote;
use App\Models\TradeScreenshot;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

uses(LazilyRefreshDatabase::class);

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function journalWithToken(array $accountNames = ['Sim101']): array
{
    $user    = User::factory()->create();
    $journal = $user->journal;
    $journal->update(['timezone' => 'America/New_York']);
    foreach ($accountNames as $name) {
        Account::factory()->create(['journal_id' => $journal->id, 'name' => $name]);
    }
    $token = $journal->rotateIngestToken();
    return [$journal, $token];
}

function journalWithTokenNoTimezone(): array
{
    $user    = User::factory()->create();
    $journal = $user->journal; // timezone is null by default
    $token   = $journal->rotateIngestToken();
    return [$journal, $token];
}

function minimalPayload(array $overrides = []): array
{
    return array_replace_recursive([
        'schema_version'   => 1,
        'source'           => 'ninjatrader_8',
        'addon_version'    => '1.0.0',
        'trade_id'         => 'NT8-TEST-' . uniqid(),
        'account_name'     => 'Sim101',
        'connection'       => 'Ninjatrader',
        'trade_type'       => '2EL',
        'trade_type_other' => null,
        'instrument' => [
            'symbol'      => 'ES',
            'contract'    => 'ES 12-26',
            'tick_size'   => '0.25',
            'point_value' => '50',
        ],
        'direction'            => 'long',
        'quantity'             => 1,
        'total_entry_quantity' => 1,
        'entry' => [
            'occurred_at'   => '2026-09-24T09:30:00-04:00',
            'average_price' => '5000.00',
            'order_name'    => 'Entry',
        ],
        'exit' => [
            'occurred_at'   => '2026-09-24T09:35:00-04:00',
            'average_price' => '5002.00',
            'order_name'    => 'Target1',
            'reason'        => 'profit_target',
        ],
        'performance' => [
            'points'     => '2.00',
            'ticks'      => 8,
            'gross_pnl'  => '100.00',
            'commission' => '5.97',
            'fees'       => null,
            'net_pnl'    => '94.03',
        ],
        'excursion' => [
            'mae_points'           => '0.50',
            'mfe_points'           => '2.25',
            'max_adverse_price'    => '4999.50',
            'max_favorable_price'  => '5002.25',
            'complete'             => true,
        ],
        'executions' => [
            [
                'execution_id'       => 'exec-1',
                'order_id'           => 'ord-1',
                'occurred_at'        => '2026-09-24T09:30:00-04:00',
                'action'             => 'buy',
                'role'               => 'entry',
                'quantity'           => 1,
                'allocated_quantity' => 1,
                'price'              => '5000.00',
                'commission'         => '2.99',
                'fee'                => null,
                'order_name'         => 'Entry',
                'position_after'     => 1,
            ],
            [
                'execution_id'       => 'exec-2',
                'order_id'           => 'ord-2',
                'occurred_at'        => '2026-09-24T09:35:00-04:00',
                'action'             => 'sell',
                'role'               => 'exit',
                'quantity'           => 1,
                'allocated_quantity' => 1,
                'price'              => '5002.00',
                'commission'         => '2.98',
                'fee'                => null,
                'order_name'         => 'Target1',
                'position_after'     => 0,
            ],
        ],
        'notes'          => [],
        'screenshot'     => null,
    ], $overrides);
}

function postTrade(array $payload, string $token): \Illuminate\Testing\TestResponse
{
    return test()->postJson('/api/v1/trades', $payload, [
        'Authorization' => "Bearer {$token}",
    ]);
}

// ---------------------------------------------------------------------------
// Authentication
// ---------------------------------------------------------------------------

test('unauthenticated request returns 401', function () {
    postTrade(minimalPayload(), 'bad-token')->assertStatus(401);
});

test('missing authorization header returns 401', function () {
    test()->postJson('/api/v1/trades', minimalPayload())->assertStatus(401);
});

// ---------------------------------------------------------------------------
// Successful intake
// ---------------------------------------------------------------------------

test('valid payload returns 201 and creates a trade', function () {
    [$journal, $token] = journalWithToken();

    postTrade(minimalPayload(), $token)
        ->assertStatus(201)
        ->assertJsonStructure(['data' => ['id', 'status', 'received_at', 'trade_url']])
        ->assertJsonPath('data.status', 'accepted');

    expect(Trade::count())->toBe(1)
        ->and(TradeExecution::count())->toBe(2);
});

test('trade is stored with correct fields', function () {
    [$journal, $token] = journalWithToken();

    postTrade(minimalPayload(), $token)->assertStatus(201);

    $trade = Trade::first();
    expect($trade->instrument_symbol)->toBe('ES')
        ->and($trade->instrument)->toBe('ES 12-26')
        ->and((float) $trade->points)->toBe(2.0)
        ->and((float) $trade->gross_pnl)->toBe(100.0)
        ->and((float) $trade->net_pnl)->toBe(94.03)
        ->and($trade->excursion_complete)->toBeTrue()
        ->and($trade->source)->toBe('ninjatrader_8')
        ->and($trade->addon_version)->toBe('1.0.0');
});

test('stop_price is stored when present', function () {
    [$journal, $token] = journalWithToken();

    postTrade(minimalPayload(['stop_price' => '4998.50']), $token)->assertStatus(201);

    expect((float) Trade::first()->stop_price)->toBe(4998.5);
});

test('stop_price is optional and stored as null when absent', function () {
    [$journal, $token] = journalWithToken();

    postTrade(minimalPayload(), $token)->assertStatus(201);

    expect(Trade::first()->stop_price)->toBeNull();
});

test('an explicit null stop_price is accepted', function () {
    [$journal, $token] = journalWithToken();

    postTrade(minimalPayload(['stop_price' => null]), $token)->assertStatus(201);

    expect(Trade::first()->stop_price)->toBeNull();
});

test('legs are stored when present', function () {
    [$journal, $token] = journalWithToken();

    $payload = minimalPayload([
        'legs' => [[
            'sequence'           => 1,
            'runner'             => false,
            'exit_order_id'      => 'ord-2',
            'order_name'         => 'Target1',
            'reason'             => 'profit_target',
            'quantity'           => 1,
            'exited_at'          => '2026-09-24T09:35:00-04:00',
            'average_exit_price' => '5002.00',
            'points'             => '2.00',
            'gross_pnl'          => '100.00',
            'mae_points'         => '0.50',
            'mfe_points'         => '2.25',
        ]],
    ]);

    postTrade($payload, $token)->assertStatus(201);

    expect(TradeLeg::count())->toBe(1)
        ->and(TradeLeg::first()->runner)->toBeFalse();
});

test('notes from the addOn are stored', function () {
    [$journal, $token] = journalWithToken();

    $payload = minimalPayload([
        'notes' => [[
            'body'        => 'H2 at the EMA.',
            'phase'       => 'pre_trade',
            'occurred_at' => '2026-09-24T09:29:40-04:00',
        ]],
    ]);

    postTrade($payload, $token)->assertStatus(201);

    expect(TradeNote::count())->toBe(1)
        ->and(TradeNote::first()->body)->toBe('H2 at the EMA.');
});

test('screenshot file is stored and recorded', function () {
    Storage::fake('local');
    [$journal, $token] = journalWithToken();

    $file = UploadedFile::fake()->image('chart.png');

    test()->post('/api/v1/trades', ['trade' => json_encode(minimalPayload()), 'screenshot_file' => $file], [
        'Authorization' => "Bearer {$token}",
    ])->assertStatus(201);

    expect(TradeScreenshot::count())->toBe(1);
    $screenshot = TradeScreenshot::first();
    expect($screenshot->source->value)->toBe('nt8')
        ->and($screenshot->disk)->toBe('local');
});

// ---------------------------------------------------------------------------
// Idempotency
// ---------------------------------------------------------------------------

test('re-sending the same trade_id returns 200 with duplicate status', function () {
    [$journal, $token] = journalWithToken();
    $payload = minimalPayload();

    postTrade($payload, $token)->assertStatus(201);
    postTrade($payload, $token)
        ->assertStatus(200)
        ->assertJsonPath('data.status', 'duplicate');

    expect(Trade::count())->toBe(1);
});

test('Idempotency-Key header deduplicates independently of trade_id', function () {
    [$journal, $token] = journalWithToken();
    $payload = minimalPayload();

    postTrade($payload, $token)->assertStatus(201);

    // Same payload but different trade_id — Idempotency-Key matches original trade_id
    $payload2 = array_merge($payload, ['trade_id' => 'NT8-TEST-different']);

    test()->postJson('/api/v1/trades', $payload2, [
        'Authorization'   => "Bearer {$token}",
        'Idempotency-Key' => $payload['trade_id'],
    ])->assertStatus(200)->assertJsonPath('data.status', 'duplicate');

    expect(Trade::count())->toBe(1);
});

// ---------------------------------------------------------------------------
// Tenant isolation
// ---------------------------------------------------------------------------

test('a valid token only accepts trades into its own journal', function () {
    [$journalA, $tokenA] = journalWithToken();
    [$journalB, $tokenB] = journalWithToken();
    $payload = minimalPayload();

    postTrade($payload, $tokenA)->assertStatus(201);
    postTrade($payload, $tokenB)->assertStatus(201);

    expect(Trade::count())->toBe(2);
});

// ---------------------------------------------------------------------------
// Validation
// ---------------------------------------------------------------------------

test('missing required fields return 422', function () {
    [$journal, $token] = journalWithToken();

    test()->postJson('/api/v1/trades', [], ['Authorization' => "Bearer {$token}"])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['trade_id', 'instrument', 'executions']);
});

test('exit.occurred_at must be after entry.occurred_at', function () {
    [$journal, $token] = journalWithToken();

    $payload = minimalPayload();
    $payload['exit']['occurred_at'] = '2026-09-24T09:00:00-04:00'; // before entry

    postTrade($payload, $token)
        ->assertStatus(422)
        ->assertJsonValidationErrors(['exit.occurred_at']);
});

test('non-positive prices, instrument specs and negative commissions return 422', function () {
    [$journal, $token] = journalWithToken();

    $payload = minimalPayload();
    $payload['instrument']['tick_size']       = '0';
    $payload['entry']['average_price']        = '-5000.00';
    $payload['executions'][0]['price']        = '0';
    $payload['performance']['commission']     = '-1.00';
    $payload['stop_price']                    = '0';

    postTrade($payload, $token)
        ->assertStatus(422)
        ->assertJsonValidationErrors([
            'instrument.tick_size',
            'entry.average_price',
            'executions.0.price',
            'performance.commission',
            'stop_price',
        ]);

    expect(Trade::count())->toBe(0);
});

test('an unknown account is created from the payload', function () {
    [$journal, $token] = journalWithToken([]);

    $payload = minimalPayload(['account_name' => 'APEX-24570-135', 'connection' => 'Rithmic']);

    postTrade($payload, $token)->assertStatus(201);

    $account = Account::where('journal_id', $journal->id)->where('name', 'APEX-24570-135')->first();
    expect($account)->not->toBeNull()
        ->and($account->connection)->toBe('Rithmic')
        ->and($account->account_type)->toBe(\App\Enums\AccountType::Funded)
        ->and(Trade::first()->account_id)->toBe($account->id);
});

test('an unknown Sim or Playback account is created as a sim account', function () {
    [$journal, $token] = journalWithToken([]);

    postTrade(minimalPayload(['account_name' => 'Sim101']), $token)->assertStatus(201);
    postTrade(minimalPayload(['account_name' => 'Playback101']), $token)->assertStatus(201);

    expect(Account::where('journal_id', $journal->id)->pluck('account_type', 'name')->map->value->sortKeys()->all())
        ->toBe(['Playback101' => 'sim', 'Sim101' => 'sim']);
});

test('an existing account is reused and left as the trader set it', function () {
    [$journal, $token] = journalWithToken([]);
    $existing = Account::factory()->create([
        'journal_id'   => $journal->id,
        'name'         => 'APEX-24570-135',
        'connection'   => 'Rithmic',
        'account_type' => \App\Enums\AccountType::Eval,
    ]);

    postTrade(minimalPayload(['account_name' => 'APEX-24570-135', 'connection' => 'Other']), $token)->assertStatus(201);

    expect(Account::where('journal_id', $journal->id)->count())->toBe(1)
        ->and($existing->fresh()->account_type)->toBe(\App\Enums\AccountType::Eval)
        ->and($existing->fresh()->connection)->toBe('Rithmic')
        ->and(Trade::first()->account_id)->toBe($existing->id);
});

test('the same account name in another journal is not shared', function () {
    [$journalA, $tokenA] = journalWithToken([]);
    [$journalB, $tokenB] = journalWithToken([]);

    postTrade(minimalPayload(['account_name' => 'APEX-24570-135']), $tokenA)->assertStatus(201);
    postTrade(minimalPayload(['account_name' => 'APEX-24570-135']), $tokenB)->assertStatus(201);

    expect(Account::where('name', 'APEX-24570-135')->pluck('journal_id')->sort()->values()->all())
        ->toBe([$journalA->id, $journalB->id]);
});

test('exit.occurred_at may equal entry.occurred_at because NT8 timestamps have one-second resolution', function () {
    [$journal, $token] = journalWithToken();

    $payload = minimalPayload();
    $payload['exit']['occurred_at'] = $payload['entry']['occurred_at'];
    $payload['executions'][1]['occurred_at'] = $payload['entry']['occurred_at'];

    postTrade($payload, $token)->assertStatus(201);

    expect(Trade::count())->toBe(1);
});

test('a trade without an ATM (blank entry, exit and leg names) is accepted', function () {
    [$journal, $token] = journalWithToken();

    $payload = minimalPayload();
    $payload['entry']['order_name'] = null;
    $payload['exit']['order_name'] = null;
    $payload['exit']['reason'] = 'other';
    $payload['legs'] = [[
        'sequence' => 1, 'runner' => false, 'exit_order_id' => 'ord-2', 'order_name' => null, 'reason' => 'other',
        'quantity' => 1, 'exited_at' => '2026-09-24T09:35:00-04:00', 'average_exit_price' => '5002.00',
        'points' => '2.00', 'gross_pnl' => '100.00', 'mae_points' => null, 'mfe_points' => null,
    ]];
    unset($payload['executions'][0]['order_name'], $payload['executions'][1]['order_name']);

    postTrade($payload, $token)->assertStatus(201);

    $trade = Trade::first();
    expect($trade->entry_order_name)->toBe('')
        ->and($trade->exit_order_name)->toBe('')
        ->and(TradeLeg::first()->order_name)->toBe('');
});

test('executions with a blank order name are stored', function () {
    [$journal, $token] = journalWithToken();

    // NT8 leaves the order name blank for chart-trader orders without an ATM.
    $payload = minimalPayload();
    $payload['executions'][0]['order_name'] = null;
    unset($payload['executions'][1]['order_name']);

    postTrade($payload, $token)->assertStatus(201);

    expect(TradeExecution::pluck('order_name')->all())->toBe(['', '']);
});

test('copier data from an older AddOn is accepted but ignored', function () {
    [$journal, $token] = journalWithToken(['Sim101']);

    // AddOn versions from before #28 nest follower copies into the master's payload, and installs in
    // the field may still run one. The server no longer reconciles copies (#27), but a trade must not
    // be rejected for carrying them — and they must not leak into the stored trade or create follower
    // accounts from a master's payload.
    $payload = minimalPayload([
        'copies_source'  => 'copier_live',
        'copies_summary' => ['matched' => 0, 'missed' => 1],
        'copies'         => [[
            'account_name' => 'PA-APEX-24570-11',
            'status'       => 'missed',
            'warnings'     => ['expected 1 MES, no entry within 5 seconds'],
            'quantity'     => 0,
            'executions'   => [],
        ]],
    ]);

    postTrade($payload, $token)->assertStatus(201);

    $trade = Trade::sole();
    expect(Account::where('journal_id', $journal->id)->pluck('name')->all())->toBe(['Sim101'])
        ->and($trade->raw_payload)->not->toHaveKeys(['copies', 'copies_source', 'copies_summary']);
});

test('screenshot_file must be an image type', function () {
    Storage::fake('local');
    [$journal, $token] = journalWithToken();

    $file = UploadedFile::fake()->create('data.csv', 100, 'text/csv');

    test()->post('/api/v1/trades', ['trade' => json_encode(minimalPayload()), 'screenshot_file' => $file], [
        'Authorization' => "Bearer {$token}",
    ])->assertStatus(422)
      ->assertJsonValidationErrors(['screenshot_file']);
});

// ---------------------------------------------------------------------------
// Timezone guard
// ---------------------------------------------------------------------------

test('intake is rejected with 422 when journal has no timezone', function () {
    [$journal, $token] = journalWithTokenNoTimezone();

    test()->post('/api/v1/trades', minimalPayload(), [
        'Authorization' => "Bearer {$token}",
    ])->assertStatus(422)
      ->assertJsonPath('errors.timezone.0', fn ($msg) => str_contains($msg, 'timezone'));
});
