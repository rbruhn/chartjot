<?php

use App\Models\Account;
use App\Models\TradeNote;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

function noteJournalAndToken(): array
{
    $user    = User::factory()->create();
    $journal = $user->journal;
    $journal->update(['timezone' => 'America/New_York']);
    Account::factory()->create(['journal_id' => $journal->id, 'name' => 'Sim101']);
    $token = $journal->rotateIngestToken();
    return [$journal, $token];
}

function createTradeViaApi(string $token): string
{
    $payload = [
        'schema_version' => 1, 'source' => 'ninjatrader_8', 'addon_version' => '1.0.0',
        'trade_id' => 'NT8-NOTE-' . uniqid(), 'account_name' => 'Sim101', 'connection' => 'Ninjatrader',
        'trade_type' => '2EL', 'trade_type_other' => null,
        'instrument' => ['symbol' => 'ES', 'contract' => 'ES 12-26', 'tick_size' => '0.25', 'point_value' => '50'],
        'direction' => 'long', 'quantity' => 1, 'total_entry_quantity' => 1,
        'entry' => ['occurred_at' => '2026-09-24T09:30:00-04:00', 'average_price' => '5000.00', 'order_name' => 'Entry'],
        'exit' => ['occurred_at' => '2026-09-24T09:35:00-04:00', 'average_price' => '5002.00', 'order_name' => 'Target1', 'reason' => 'profit_target'],
        'performance' => ['points' => '2.00', 'ticks' => 8, 'gross_pnl' => '100.00', 'commission' => '5.97', 'fees' => null, 'net_pnl' => '94.03'],
        'excursion' => ['mae_points' => '0.50', 'mfe_points' => '2.25', 'max_adverse_price' => '4999.50', 'max_favorable_price' => '5002.25', 'complete' => true],
        'executions' => [
            ['execution_id' => uniqid(), 'order_id' => 'o1', 'occurred_at' => '2026-09-24T09:30:00-04:00', 'action' => 'buy', 'role' => 'entry', 'quantity' => 1, 'allocated_quantity' => 1, 'price' => '5000.00', 'commission' => '2.99', 'fee' => null, 'order_name' => 'Entry', 'position_after' => 1],
            ['execution_id' => uniqid(), 'order_id' => 'o2', 'occurred_at' => '2026-09-24T09:35:00-04:00', 'action' => 'sell', 'role' => 'exit', 'quantity' => 1, 'allocated_quantity' => 1, 'price' => '5002.00', 'commission' => '2.98', 'fee' => null, 'order_name' => 'Target1', 'position_after' => 0],
        ],
        'notes' => [], 'screenshot' => null, 'copies_source' => null, 'copies' => [],
    ];

    $response = test()->postJson('/api/v1/trades', $payload, ['Authorization' => "Bearer {$token}"]);
    return $response->json('data.id');
}

test('a note can be appended to a trade via the API', function () {
    [$journal, $token] = noteJournalAndToken();
    $tradeUuid = createTradeViaApi($token);

    test()->postJson("/api/v1/trades/{$tradeUuid}/notes", [
        'body'        => 'Runner stopped. Good management.',
        'phase'       => 'post_trade',
        'occurred_at' => '2026-09-24T09:36:40-04:00',
    ], ['Authorization' => "Bearer {$token}"])
        ->assertStatus(201)
        ->assertJsonStructure(['data' => ['id', 'body', 'phase', 'occurred_at']]);

    expect(TradeNote::count())->toBe(1);
});

test('cannot append a note to another journal trade', function () {
    [$journalA, $tokenA] = noteJournalAndToken();
    [$journalB, $tokenB] = noteJournalAndToken();

    $tradeUuid = createTradeViaApi($tokenA);

    test()->postJson("/api/v1/trades/{$tradeUuid}/notes", [
        'body'        => 'Sneaking in.',
        'phase'       => 'general',
        'occurred_at' => '2026-09-24T09:37:00-04:00',
    ], ['Authorization' => "Bearer {$tokenB}"])
        ->assertStatus(404);

    expect(TradeNote::count())->toBe(0);
});

test('note requires body phase and occurred_at', function () {
    [$journal, $token] = noteJournalAndToken();
    $tradeUuid = createTradeViaApi($token);

    test()->postJson("/api/v1/trades/{$tradeUuid}/notes", [], ['Authorization' => "Bearer {$token}"])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['body', 'phase', 'occurred_at']);
});

test('note body over 10,000 characters returns 422', function () {
    [$journal, $token] = noteJournalAndToken();
    $tradeUuid = createTradeViaApi($token);

    test()->postJson("/api/v1/trades/{$tradeUuid}/notes", [
        'body'        => str_repeat('a', 10001),
        'phase'       => 'post_trade',
        'occurred_at' => '2026-09-24T09:40:00-04:00',
    ], ['Authorization' => "Bearer {$token}"])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['body']);

    expect(TradeNote::count())->toBe(0);
});
