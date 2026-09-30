<?php

use App\Jobs\ImportTradesMaeMfeCsv;
use App\Models\Account;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(LazilyRefreshDatabase::class);

// ---------------------------------------------------------------------------
// Job
// ---------------------------------------------------------------------------

function maeUploadJournal()
{
    $journal = User::factory()->create()->journal;
    $journal->update(['timezone' => 'America/New_York']);
    Account::factory()->create(['journal_id' => $journal->id, 'name' => 'Sim101']);

    return $journal;
}

test('the job caches the import result and deletes the uploaded file', function () {
    $journal = maeUploadJournal();
    $path = tempnam(sys_get_temp_dir(), 'mae_');
    copy(base_path('tests/Fixtures/ninjatrader/Trades.csv'), $path);

    (new ImportTradesMaeMfeCsv($journal, $path, 'abc'))->handle(app(\App\Services\TradesMaeMfeImporter::class));

    $cached = Cache::get('mae_mfe_import_abc');
    expect($cached['status'])->toBe('complete')
        ->and($cached['result'])->toHaveKeys(['legs_imported', 'trades_enriched', 'rows_skipped', 'errors'])
        ->and(file_exists($path))->toBeFalse();
});

test('the job caches a failure (e.g. the wrong kind of file) and still deletes the upload', function () {
    $journal = maeUploadJournal();
    $path = tempnam(sys_get_temp_dir(), 'mae_');
    copy(base_path('tests/Fixtures/ninjatrader/Executions.csv'), $path);

    (new ImportTradesMaeMfeCsv($journal, $path, 'def'))->handle(app(\App\Services\TradesMaeMfeImporter::class));

    $cached = Cache::get('mae_mfe_import_def');
    expect($cached['status'])->toBe('failed')
        ->and($cached['error'])->toContain('Trades export')
        ->and(file_exists($path))->toBeFalse();
});

// ---------------------------------------------------------------------------
// Upload component & Settings page
// ---------------------------------------------------------------------------

use App\Models\Trade;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

function withCsvTrade($journal): void
{
    Trade::factory()->create([
        'journal_id' => $journal->id,
        'account_id' => $journal->accounts()->first()->id,
        'source'     => 'csv_import',
    ]);
}

test('the Settings page has a separate MAE/MFE upload beside the Executions one', function () {
    $journal = maeUploadJournal();

    $this->actingAs($journal->user)->get(route('journal.settings.edit'))
        ->assertOk()
        ->assertSeeLivewire('journal.csv-import')
        ->assertSeeLivewire('journal.trades-mae-mfe-import')
        ->assertSeeInOrder(['Import executions', 'Import MAE/MFE']);
});

test('uploading a Trades export stores it and queues the import', function () {
    Storage::fake('local');
    Queue::fake();
    $journal = maeUploadJournal();
    withCsvTrade($journal);

    Livewire::actingAs($journal->user)
        ->test('journal.trades-mae-mfe-import', ['journal' => $journal])
        ->set('csvFile', UploadedFile::fake()->createWithContent('Trades.csv', file_get_contents(base_path('tests/Fixtures/ninjatrader/Trades.csv'))))
        ->assertSet('state', 'processing')
        ->assertHasNoErrors();

    Queue::assertPushed(ImportTradesMaeMfeCsv::class);
    expect(Storage::disk('local')->allFiles('csv-imports'))->toHaveCount(1);
});

test('polling shows the finished result', function () {
    $journal = maeUploadJournal();
    withCsvTrade($journal);
    Cache::put('mae_mfe_import_xyz', ['status' => 'complete', 'result' => [
        'legs_imported' => 156, 'trades_enriched' => 73, 'rows_skipped' => 0, 'errors' => [],
    ]], 60);

    Livewire::actingAs($journal->user)
        ->test('journal.trades-mae-mfe-import', ['journal' => $journal])
        ->set('state', 'processing')->set('importId', 'xyz')
        ->call('poll')
        ->assertSet('state', 'complete')
        ->assertSee('73 trades enriched')
        ->assertSee('156 legs');
});

test('it explains the prerequisite until trades have been imported from Executions', function () {
    $journal = maeUploadJournal();

    Livewire::actingAs($journal->user)
        ->test('journal.trades-mae-mfe-import', ['journal' => $journal])
        ->assertSee('Import your Executions export first')
        ->assertDontSeeHtml('id="mae-csv-file-input"');
});

test('only CSV/TXT files are accepted', function () {
    Queue::fake();
    $journal = maeUploadJournal();
    withCsvTrade($journal);

    Livewire::actingAs($journal->user)
        ->test('journal.trades-mae-mfe-import', ['journal' => $journal])
        ->set('csvFile', UploadedFile::fake()->create('chart.png', 10, 'image/png'))
        ->assertHasErrors('csvFile');

    Queue::assertNothingPushed();
});
