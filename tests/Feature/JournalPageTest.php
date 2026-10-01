<?php

use App\Enums\Direction;
use App\Enums\ExitReason;
use App\Enums\NotePhase;
use App\Enums\TradeType;
use App\Models\Account;
use App\Models\Journal;
use App\Models\Trade;
use App\Models\TradeNote;
use App\Models\TradeScreenshot;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(LazilyRefreshDatabase::class);

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function journalUser(): array
{
    $user    = User::factory()->create();
    $journal = $user->journal;
    return [$user, $journal];
}

function tradeInJournal(Journal $journal, array $attrs = []): Trade
{
    $account = Account::factory()->create(['journal_id' => $journal->id]);
    return Trade::factory()->create(array_merge([
        'journal_id' => $journal->id,
        'account_id' => $account->id,
        'entry_at'   => now()->subHours(2),
        'exit_at'    => now()->subHours(1),
    ], $attrs));
}

// ---------------------------------------------------------------------------
// Page load
// ---------------------------------------------------------------------------

test('journal page loads for authenticated user', function () {
    [$user, $journal] = journalUser();

    $this->actingAs($user)
        ->get('/journal')
        ->assertStatus(200)
        ->assertSee($journal->name);
});

test('admin can visit the journal page like any other user', function () {
    $admin = User::factory()->create(['is_admin' => true]);

    $this->actingAs($admin)
        ->get('/journal')
        ->assertStatus(200);
});

test('unauthenticated request is redirected to login', function () {
    $this->get('/journal')->assertRedirect('/login');
});

// ---------------------------------------------------------------------------
// Trade list
// ---------------------------------------------------------------------------

test('trade list shows trades belonging to the journal', function () {
    [$user, $journal] = journalUser();
    $trade = tradeInJournal($journal);

    Livewire::actingAs($user)
        ->test('journal.trade-journal', ['journal' => $journal])
        ->assertSee($trade->instrument_symbol);
});

test('trade list does not show trades from another journal', function () {
    [$user, $journal]       = journalUser();
    [$otherUser, $otherJournal] = journalUser();
    $otherTrade = tradeInJournal($otherJournal, ['instrument_symbol' => 'ISOLATEDX']);

    Livewire::actingAs($user)
        ->test('journal.trade-journal', ['journal' => $journal])
        ->assertDontSee('ISOLATEDX');
});

test('a trade from a prior month still shows by default', function () {
    [$user, $journal] = journalUser();
    $trade = tradeInJournal($journal, [
        'instrument_symbol' => 'LASTMONTH',
        'entry_at'          => now()->subMonth(),
        'exit_at'           => now()->subMonth()->addHour(),
    ]);

    Livewire::actingAs($user)
        ->test('journal.trade-journal', ['journal' => $journal])
        ->assertSee('LASTMONTH');
});

// ---------------------------------------------------------------------------
// Filters
// ---------------------------------------------------------------------------

test('account filter narrows trade list to one account', function () {
    [$user, $journal] = journalUser();

    $acctA = Account::factory()->create(['journal_id' => $journal->id, 'name' => 'APEX-A']);
    $acctB = Account::factory()->create(['journal_id' => $journal->id, 'name' => 'APEX-B']);

    Trade::factory()->create(['journal_id' => $journal->id, 'account_id' => $acctA->id, 'instrument_symbol' => 'TRADEACCT_A', 'entry_at' => now()->subHour(), 'exit_at' => now()->subMinutes(30)]);
    Trade::factory()->create(['journal_id' => $journal->id, 'account_id' => $acctB->id, 'instrument_symbol' => 'TRADEACCT_B', 'entry_at' => now()->subHour(), 'exit_at' => now()->subMinutes(30)]);

    Livewire::actingAs($user)
        ->test('journal.trade-journal', ['journal' => $journal])
        ->set('selectedAccountIds', [$acctA->id])
        ->assertSee('TRADEACCT_A')
        ->assertDontSee('TRADEACCT_B');
});

test('needs journal entry filter shows only trades without notes', function () {
    [$user, $journal] = journalUser();

    $unnoted = tradeInJournal($journal, ['instrument_symbol' => 'UNNOTED']);
    $noted   = tradeInJournal($journal, ['instrument_symbol' => 'NOTED123']);

    TradeNote::factory()->create([
        'trade_id'    => $noted->id,
        'created_by'  => $user->id,
        'phase'       => NotePhase::PostTrade,
        'occurred_at' => now(),
    ]);

    Livewire::actingAs($user)
        ->test('journal.trade-journal', ['journal' => $journal])
        ->set('needsNote', true)
        ->assertSee('UNNOTED')
        ->assertDontSee('NOTED123');
});

test('search filter matches instrument symbol', function () {
    [$user, $journal] = journalUser();

    tradeInJournal($journal, ['instrument_symbol' => 'NQSYMBOL', 'instrument' => 'NQ 12-26']);
    tradeInJournal($journal, ['instrument_symbol' => 'ESSYMBOL', 'instrument' => 'ES 12-26']);

    Livewire::actingAs($user)
        ->test('journal.trade-journal', ['journal' => $journal])
        ->set('search', 'NQSYMBOL')
        ->assertSee('NQSYMBOL')
        ->assertDontSee('ESSYMBOL');
});

test('search filter matches trade type by enum value', function () {
    [$user, $journal] = journalUser();

    $acct = Account::factory()->create(['journal_id' => $journal->id]);
    Trade::factory()->create(['journal_id' => $journal->id, 'account_id' => $acct->id, 'trade_type' => TradeType::SecondEntryLong,  'instrument_symbol' => 'ES', 'entry_at' => now()->subHour(), 'exit_at' => now()->subMinutes(30)]);
    Trade::factory()->create(['journal_id' => $journal->id, 'account_id' => $acct->id, 'trade_type' => TradeType::SecondEntryShort, 'instrument_symbol' => 'ES', 'entry_at' => now()->subHour(), 'exit_at' => now()->subMinutes(30)]);

    $component = Livewire::actingAs($user)
        ->test('journal.trade-journal', ['journal' => $journal])
        ->set('search', '2EL');

    expect($component->get('summary')['total'])->toBe(1);
});

test('search filter matches trade type by label', function () {
    [$user, $journal] = journalUser();

    $acct = Account::factory()->create(['journal_id' => $journal->id]);
    Trade::factory()->create(['journal_id' => $journal->id, 'account_id' => $acct->id, 'trade_type' => TradeType::SecondEntryLong,  'instrument_symbol' => 'ES', 'entry_at' => now()->subHour(), 'exit_at' => now()->subMinutes(30)]);
    Trade::factory()->create(['journal_id' => $journal->id, 'account_id' => $acct->id, 'trade_type' => TradeType::SecondEntryShort, 'instrument_symbol' => 'ES', 'entry_at' => now()->subHour(), 'exit_at' => now()->subMinutes(30)]);

    $component = Livewire::actingAs($user)
        ->test('journal.trade-journal', ['journal' => $journal])
        ->set('search', 'second entry long');

    expect($component->get('summary')['total'])->toBe(1);
});

// ---------------------------------------------------------------------------
// Summary strip
// ---------------------------------------------------------------------------

test('summary strip reflects filtered trade count', function () {
    [$user, $journal] = journalUser();

    $acctA = Account::factory()->create(['journal_id' => $journal->id]);
    $acctB = Account::factory()->create(['journal_id' => $journal->id]);

    Trade::factory()->create(['journal_id' => $journal->id, 'account_id' => $acctA->id, 'entry_at' => now()->subHour(), 'exit_at' => now()->subMinutes(30)]);
    Trade::factory()->create(['journal_id' => $journal->id, 'account_id' => $acctB->id, 'entry_at' => now()->subHour(), 'exit_at' => now()->subMinutes(30)]);

    $component = Livewire::actingAs($user)
        ->test('journal.trade-journal', ['journal' => $journal]);

    expect($component->get('summary')['total'])->toBe(2);

    $component->set('selectedAccountIds', [$acctA->id]);

    expect($component->get('summary')['total'])->toBe(1);
});

// ---------------------------------------------------------------------------
// Trade selection
// ---------------------------------------------------------------------------

test('selecting a trade sets selectedUuid', function () {
    [$user, $journal] = journalUser();
    $trade = tradeInJournal($journal);

    Livewire::actingAs($user)
        ->test('journal.trade-journal', ['journal' => $journal])
        ->call('selectTrade', $trade->uuid)
        ->assertSet('selectedUuid', $trade->uuid);
});

test('selecting the same trade again keeps it selected', function () {
    [$user, $journal] = journalUser();
    $trade = tradeInJournal($journal);

    Livewire::actingAs($user)
        ->test('journal.trade-journal', ['journal' => $journal])
        ->call('selectTrade', $trade->uuid)
        ->call('selectTrade', $trade->uuid)
        ->assertSet('selectedUuid', $trade->uuid);
});

// ---------------------------------------------------------------------------
// Note CRUD
// ---------------------------------------------------------------------------

test('adding a note creates it and shows it in the list', function () {
    [$user, $journal] = journalUser();
    $trade = tradeInJournal($journal);

    Livewire::actingAs($user)
        ->test('journal.trade-journal', ['journal' => $journal])
        ->call('selectTrade', $trade->uuid)
        ->set('addingNote', true)
        ->set('newNoteBody', 'H2 at the EMA, clean entry.')
        ->set('newNotePhase', 'post_trade')
        ->call('addNote');

    expect(TradeNote::count())->toBe(1)
        ->and(TradeNote::first()->body)->toBe('H2 at the EMA, clean entry.')
        ->and(TradeNote::first()->phase)->toBe(NotePhase::PostTrade);
});

test('adding a note with empty body returns validation error', function () {
    [$user, $journal] = journalUser();
    $trade = tradeInJournal($journal);

    Livewire::actingAs($user)
        ->test('journal.trade-journal', ['journal' => $journal])
        ->call('selectTrade', $trade->uuid)
        ->set('addingNote', true)
        ->set('newNoteBody', '')
        ->call('addNote')
        ->assertHasErrors(['newNoteBody' => 'required']);

    expect(TradeNote::count())->toBe(0);
});

test('editing a note updates its body', function () {
    [$user, $journal] = journalUser();
    $trade = tradeInJournal($journal);
    $note = TradeNote::factory()->create([
        'trade_id'    => $trade->id,
        'created_by'  => $user->id,
        'body'        => 'Original note.',
        'phase'       => NotePhase::PostTrade,
        'occurred_at' => now(),
    ]);

    Livewire::actingAs($user)
        ->test('journal.trade-journal', ['journal' => $journal])
        ->call('selectTrade', $trade->uuid)
        ->call('startEditNote', $note->id)
        ->set('editNoteBody', 'Updated note content.')
        ->call('saveNote');

    expect($note->fresh()->body)->toBe('Updated note content.');
});

test('deleting a note removes it', function () {
    [$user, $journal] = journalUser();
    $trade = tradeInJournal($journal);
    $note = TradeNote::factory()->create([
        'trade_id'    => $trade->id,
        'created_by'  => $user->id,
        'body'        => 'To be deleted.',
        'phase'       => NotePhase::PostTrade,
        'occurred_at' => now(),
    ]);

    Livewire::actingAs($user)
        ->test('journal.trade-journal', ['journal' => $journal])
        ->call('selectTrade', $trade->uuid)
        ->call('deleteNote', $note->id);

    expect(TradeNote::count())->toBe(0);
});

test('cannot delete a note belonging to another journal', function () {
    [$user, $journal]       = journalUser();
    [$otherUser, $otherJournal] = journalUser();

    $otherTrade = tradeInJournal($otherJournal);
    $note = TradeNote::factory()->create([
        'trade_id'    => $otherTrade->id,
        'created_by'  => $otherUser->id,
        'body'        => 'Other journal note.',
        'phase'       => NotePhase::PostTrade,
        'occurred_at' => now(),
    ]);

    Livewire::actingAs($user)
        ->test('journal.trade-journal', ['journal' => $journal])
        ->call('deleteNote', $note->id);

    expect(TradeNote::count())->toBe(1);
});

// ---------------------------------------------------------------------------
// Screenshot upload
// ---------------------------------------------------------------------------

test('screenshot upload stores file and creates record', function () {
    Storage::fake('local');
    [$user, $journal] = journalUser();
    $trade = tradeInJournal($journal);

    Livewire::actingAs($user)
        ->test('journal.trade-journal', ['journal' => $journal])
        ->call('selectTrade', $trade->uuid)
        ->set('screenshotUpload', UploadedFile::fake()->image('chart.png'))
        ->call('uploadScreenshot');

    expect(TradeScreenshot::count())->toBe(1)
        ->and(TradeScreenshot::first()->source->value)->toBe('manual_upload');
});

test('screenshot upload rejects non-image files', function () {
    Storage::fake('local');
    [$user, $journal] = journalUser();
    $trade = tradeInJournal($journal);

    Livewire::actingAs($user)
        ->test('journal.trade-journal', ['journal' => $journal])
        ->call('selectTrade', $trade->uuid)
        ->set('screenshotUpload', UploadedFile::fake()->create('data.csv', 100, 'text/csv'))
        ->call('uploadScreenshot')
        ->assertHasErrors(['screenshotUpload']);

    expect(TradeScreenshot::count())->toBe(0);
});

// ---------------------------------------------------------------------------
// Screenshot serving
// ---------------------------------------------------------------------------

test('screenshot is served to the trade owner', function () {
    Storage::fake('local');
    [$user, $journal] = journalUser();
    $trade = tradeInJournal($journal);

    $path = "trade-screenshots/{$journal->id}/{$trade->uuid}.png";
    Storage::disk('local')->put($path, 'fake-image-data');

    $shot = TradeScreenshot::factory()->create([
        'trade_id'  => $trade->id,
        'disk'      => 'local',
        'path'      => $path,
        'mime_type' => 'image/png',
        'bytes'     => 15,
        'source'    => \App\Enums\ScreenshotSource::Nt8,
    ]);

    $this->actingAs($user)
        ->get(route('journal.screenshot', [$trade, $shot]))
        ->assertStatus(200)
        ->assertHeader('Content-Type', 'image/png');
});

test('screenshot returns 404 for another journal', function () {
    Storage::fake('local');
    [$user, $journal]       = journalUser();
    [$otherUser, $otherJournal] = journalUser();

    $otherTrade = tradeInJournal($otherJournal);
    $path       = "trade-screenshots/{$otherJournal->id}/{$otherTrade->uuid}.png";
    Storage::disk('local')->put($path, 'fake-image-data');

    $shot = TradeScreenshot::factory()->create([
        'trade_id'  => $otherTrade->id,
        'disk'      => 'local',
        'path'      => $path,
        'mime_type' => 'image/png',
        'bytes'     => 15,
        'source'    => \App\Enums\ScreenshotSource::Nt8,
    ]);

    $this->actingAs($user)
        ->get(route('journal.screenshot', [$otherTrade, $shot]))
        ->assertStatus(404);
});

// ---------------------------------------------------------------------------
// Account filter — selectedAccountIds
// ---------------------------------------------------------------------------

test('null selectedAccountIds shows all accounts', function () {
    [$user, $journal] = journalUser();

    $acctA = Account::factory()->create(['journal_id' => $journal->id]);
    $acctB = Account::factory()->create(['journal_id' => $journal->id]);

    Trade::factory()->create(['journal_id' => $journal->id, 'account_id' => $acctA->id, 'instrument_symbol' => 'AAAA', 'entry_at' => now()->subHour(), 'exit_at' => now()->subMinutes(30)]);
    Trade::factory()->create(['journal_id' => $journal->id, 'account_id' => $acctB->id, 'instrument_symbol' => 'BBBB', 'entry_at' => now()->subHour(), 'exit_at' => now()->subMinutes(30)]);

    Livewire::actingAs($user)
        ->test('journal.trade-journal', ['journal' => $journal])
        ->set('selectedAccountIds', null)
        ->assertSee('AAAA')
        ->assertSee('BBBB');
});

test('empty selectedAccountIds shows no trades', function () {
    [$user, $journal] = journalUser();
    tradeInJournal($journal, ['instrument_symbol' => 'VISIBLE']);

    $component = Livewire::actingAs($user)
        ->test('journal.trade-journal', ['journal' => $journal])
        ->set('selectedAccountIds', []);

    expect($component->get('summary')['total'])->toBe(0);
});

test('specific selectedAccountIds filters correctly', function () {
    [$user, $journal] = journalUser();

    $acctA = Account::factory()->create(['journal_id' => $journal->id]);
    $acctB = Account::factory()->create(['journal_id' => $journal->id]);
    $acctC = Account::factory()->create(['journal_id' => $journal->id]);

    Trade::factory()->create(['journal_id' => $journal->id, 'account_id' => $acctA->id, 'instrument_symbol' => 'TRDA', 'entry_at' => now()->subHour(), 'exit_at' => now()->subMinutes(30)]);
    Trade::factory()->create(['journal_id' => $journal->id, 'account_id' => $acctB->id, 'instrument_symbol' => 'TRDB', 'entry_at' => now()->subHour(), 'exit_at' => now()->subMinutes(30)]);
    Trade::factory()->create(['journal_id' => $journal->id, 'account_id' => $acctC->id, 'instrument_symbol' => 'TRDC', 'entry_at' => now()->subHour(), 'exit_at' => now()->subMinutes(30)]);

    Livewire::actingAs($user)
        ->test('journal.trade-journal', ['journal' => $journal])
        ->set('selectedAccountIds', [$acctA->id, $acctC->id])
        ->assertSee('TRDA')
        ->assertDontSee('TRDB')
        ->assertSee('TRDC');
});

// ---------------------------------------------------------------------------
// Trade editing
// ---------------------------------------------------------------------------

test('startEditTrade sets editingTrade and populates tradeEditForm', function () {
    [$user, $journal] = journalUser();
    $trade = tradeInJournal($journal, [
        'direction'   => Direction::Long,
        'quantity'    => 2,
        'entry_price' => '5500.00',
        'exit_price'  => '5510.00',
        'exit_reason' => ExitReason::ProfitTarget,
    ]);

    $component = Livewire::actingAs($user)
        ->test('journal.trade-journal', ['journal' => $journal])
        ->call('selectTrade', $trade->uuid)
        ->call('startEditTrade');

    $component->assertSet('editingTrade', true);
    $form = $component->get('tradeEditForm');
    expect($form['direction'])->toBe(Direction::Long->value)
        ->and($form['quantity'])->toBe('2')
        ->and($form['entry_price'])->toBe('5500.00')
        ->and($form['exit_price'])->toBe('5510.00');
});

test('cancelEditTrade resets edit state without touching the trade', function () {
    [$user, $journal] = journalUser();
    $trade = tradeInJournal($journal, ['entry_price' => '5500.00', 'exit_price' => '5510.00']);

    Livewire::actingAs($user)
        ->test('journal.trade-journal', ['journal' => $journal])
        ->call('selectTrade', $trade->uuid)
        ->call('startEditTrade')
        ->call('cancelEditTrade')
        ->assertSet('editingTrade', false)
        ->assertSet('tradeEditForm', []);

    expect((float) $trade->fresh()->entry_price)->toBe(5500.0);
});

test('saveTrade updates trade fields and recalculates pnl', function () {
    [$user, $journal] = journalUser();
    $trade = tradeInJournal($journal, [
        'direction'   => Direction::Long,
        'quantity'    => 1,
        'entry_price' => '5500.00',
        'exit_price'  => '5508.00',
        'exit_reason' => ExitReason::ProfitTarget,
        'trade_type'  => TradeType::SecondEntryLong,
        'point_value' => '50.00',
        'tick_size'   => '0.25',
        'commission'  => '2.58',
        'fees'        => '0.00',
    ]);

    $account = $trade->account;

    Livewire::actingAs($user)
        ->test('journal.trade-journal', ['journal' => $journal])
        ->call('selectTrade', $trade->uuid)
        ->call('startEditTrade')
        ->set('tradeEditForm', [
            'direction'        => Direction::Long->value,
            'quantity'         => '2',
            'entry_price'      => '5500.00',
            'exit_price'       => '5510.00',
            'entry_at'         => now()->subHours(2)->format('Y-m-d\TH:i'),
            'exit_at'          => now()->subHours(1)->format('Y-m-d\TH:i'),
            'exit_reason'      => ExitReason::ProfitTarget->value,
            'trade_type'       => TradeType::SecondEntryLong->value,
            'entry_order_name' => 'Entry',
            'exit_order_name'  => 'Target1',
        ])
        ->call('saveTrade');

    $fresh = $trade->fresh();
    expect((int) $fresh->quantity)->toBe(2)
        ->and((float) $fresh->exit_price)->toBe(5510.0)
        ->and((float) $fresh->points)->toBe(10.0)
        ->and((float) $fresh->gross_pnl)->toBe(1000.0); // 10 pts × 2 qty × $50
});

test('saveTrade resets editingTrade to false', function () {
    [$user, $journal] = journalUser();
    $trade = tradeInJournal($journal, [
        'direction'   => Direction::Long,
        'quantity'    => 1,
        'entry_price' => '5500.00',
        'exit_price'  => '5510.00',
        'exit_reason' => ExitReason::ProfitTarget,
    ]);

    Livewire::actingAs($user)
        ->test('journal.trade-journal', ['journal' => $journal])
        ->call('selectTrade', $trade->uuid)
        ->call('startEditTrade')
        ->set('tradeEditForm', [
            'direction'        => Direction::Long->value,
            'quantity'         => '1',
            'entry_price'      => '5500.00',
            'exit_price'       => '5510.00',
            'entry_at'         => now()->subHours(2)->format('Y-m-d\TH:i'),
            'exit_at'          => now()->subHours(1)->format('Y-m-d\TH:i'),
            'exit_reason'      => ExitReason::ProfitTarget->value,
            'trade_type'       => TradeType::SecondEntryLong->value,
            'entry_order_name' => 'Entry',
            'exit_order_name'  => 'Target1',
        ])
        ->call('saveTrade')
        ->assertSet('editingTrade', false)
        ->assertSet('tradeEditForm', []);
});

test('selectTrade resets any in-progress edit', function () {
    [$user, $journal] = journalUser();
    $trade  = tradeInJournal($journal);
    $trade2 = tradeInJournal($journal);

    Livewire::actingAs($user)
        ->test('journal.trade-journal', ['journal' => $journal])
        ->call('selectTrade', $trade->uuid)
        ->call('startEditTrade')
        ->call('selectTrade', $trade2->uuid)
        ->assertSet('editingTrade', false)
        ->assertSet('tradeEditForm', []);
});

test('cannot edit trade belonging to another journal', function () {
    [$user, $journal]       = journalUser();
    [$otherUser, $otherJournal] = journalUser();
    $otherTrade = tradeInJournal($otherJournal, ['entry_price' => '5500.00']);

    $component = Livewire::actingAs($user)
        ->test('journal.trade-journal', ['journal' => $journal]);

    // Attempt to forcibly set UUID from a trade that belongs to another journal
    $component->set('selectedUuid', $otherTrade->uuid)
        ->call('startEditTrade');

    // startEditTrade should silently bail (selectedTrade computed returns null for cross-journal uuid)
    $component->assertSet('editingTrade', false);
});

// ---------------------------------------------------------------------------
// Screenshot delete
// ---------------------------------------------------------------------------

test('deleteScreenshot removes file and database record', function () {
    Storage::fake('local');
    [$user, $journal] = journalUser();
    $trade = tradeInJournal($journal);

    $path = "trade-screenshots/{$journal->id}/{$trade->uuid}.png";
    Storage::disk('local')->put($path, 'fake-image-data');

    $shot = TradeScreenshot::factory()->create([
        'trade_id'  => $trade->id,
        'disk'      => 'local',
        'path'      => $path,
        'mime_type' => 'image/png',
        'bytes'     => 15,
        'source'    => \App\Enums\ScreenshotSource::ManualUpload,
    ]);

    Livewire::actingAs($user)
        ->test('journal.trade-journal', ['journal' => $journal])
        ->call('selectTrade', $trade->uuid)
        ->call('deleteScreenshot', $shot->id);

    expect(TradeScreenshot::count())->toBe(0);
    Storage::disk('local')->assertMissing($path);
});

test('cannot delete screenshot belonging to another journal', function () {
    Storage::fake('local');
    [$user, $journal]       = journalUser();
    [$otherUser, $otherJournal] = journalUser();

    $otherTrade = tradeInJournal($otherJournal);
    $path       = "trade-screenshots/{$otherJournal->id}/{$otherTrade->uuid}.png";
    Storage::disk('local')->put($path, 'fake-image-data');

    $shot = TradeScreenshot::factory()->create([
        'trade_id'  => $otherTrade->id,
        'disk'      => 'local',
        'path'      => $path,
        'mime_type' => 'image/png',
        'bytes'     => 15,
        'source'    => \App\Enums\ScreenshotSource::ManualUpload,
    ]);

    $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

    Livewire::actingAs($user)
        ->test('journal.trade-journal', ['journal' => $journal])
        ->call('deleteScreenshot', $shot->id);
});

// ---------------------------------------------------------------------------
// Trade delete
// ---------------------------------------------------------------------------

test('deleteTrade removes the trade, all associated data, and screenshot files', function () {
    Storage::fake('local');
    [$user, $journal] = journalUser();
    $trade = tradeInJournal($journal);

    \App\Models\TradeExecution::factory()->create(['trade_id' => $trade->id]);
    \App\Models\TradeNote::factory()->create(['trade_id' => $trade->id, 'created_by' => $user->id]);

    $path = "trade-screenshots/{$journal->id}/{$trade->uuid}.png";
    Storage::disk('local')->put($path, 'fake-image-data');
    TradeScreenshot::factory()->create([
        'trade_id'  => $trade->id,
        'disk'      => 'local',
        'path'      => $path,
        'mime_type' => 'image/png',
        'bytes'     => 15,
        'source'    => \App\Enums\ScreenshotSource::ManualUpload,
    ]);

    Livewire::actingAs($user)
        ->test('journal.trade-journal', ['journal' => $journal])
        ->call('selectTrade', $trade->uuid)
        ->call('deleteTrade', $trade->uuid)
        ->assertSet('selectedUuid', '');

    expect(Trade::count())->toBe(0)
        ->and(\App\Models\TradeExecution::count())->toBe(0)
        ->and(\App\Models\TradeNote::count())->toBe(0)
        ->and(TradeScreenshot::count())->toBe(0);
    Storage::disk('local')->assertMissing($path);
});

test('cannot delete a trade belonging to another journal', function () {
    [$user, $journal]            = journalUser();
    [$otherUser, $otherJournal]  = journalUser();
    $otherTrade = tradeInJournal($otherJournal);

    $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

    Livewire::actingAs($user)
        ->test('journal.trade-journal', ['journal' => $journal])
        ->call('deleteTrade', $otherTrade->uuid);

    expect(Trade::find($otherTrade->id))->not->toBeNull();
});
