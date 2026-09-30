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
