<?php

use App\Enums\ScreenshotKind;
use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\Trade;
use App\Models\TradeExecution;
use App\Models\TradeLeg;
use App\Models\TradeNote;
use App\Models\TradeScreenshot;
use App\Models\User;
use App\Support\JournalArchive;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

uses(LazilyRefreshDatabase::class);

// journal:export / journal:import (issue #106): move one trader's journal
// from the hosted site to a self-hosted copy.

beforeEach(function () {
    Storage::fake('local');
    $this->exportDir = sys_get_temp_dir().'/chartjot-export-test-'.uniqid();
});

afterEach(function () {
    File::deleteDirectory($this->exportDir);
});

/**
 * A hosted trader: two accounts (one with a deposit), a master trade with a
 * copier follower, executions, legs, a note and two chart images.
 */
function hostedJournal(): object
{
    $user = User::factory()->create(['email' => 'friend@example.com']);
    $user->journal->update(['timezone' => 'America/New_York']);

    $funded = Account::factory()->create(['journal_id' => $user->journal->id, 'name' => 'APEX-1']);
    $sim = Account::factory()->create(['journal_id' => $user->journal->id, 'name' => 'Sim101']);
    AccountTransaction::factory()->create(['account_id' => $funded->id, 'amount' => 500]);

    $master = Trade::factory()->create(['journal_id' => $user->journal->id, 'account_id' => $funded->id, 'stop_price' => '5000.25']);
    $follower = Trade::factory()->create(['journal_id' => $user->journal->id, 'account_id' => $sim->id, 'master_trade_id' => $master->id]);

    TradeExecution::factory()->count(2)->create(['trade_id' => $master->id]);
    TradeLeg::factory()->create(['trade_id' => $master->id]);
    TradeNote::factory()->create(['trade_id' => $master->id, 'created_by' => $user->id, 'body' => 'Waited for the pullback']);

    foreach ([ScreenshotKind::Exit, ScreenshotKind::Entry] as $kind) {
        $path = "trade-screenshots/{$user->journal->id}/{$master->uuid}-{$kind->value}.png";
        Storage::disk('local')->put($path, "png-{$kind->value}");
        TradeScreenshot::factory()->create(['trade_id' => $master->id, 'kind' => $kind, 'disk' => 'local', 'path' => $path]);
    }

    return (object) compact('user', 'funded', 'sim', 'master', 'follower');
}

/** Exports the journal and returns the zip's path. */
function exportJournal($test, string $user): string
{
    $test->artisan('journal:export', ['user' => $user, '--path' => $test->exportDir])->assertSuccessful();

    return File::files($test->exportDir)[0]->getPathname();
}

/** Simulates the other computer: the hosted trader and their images are gone. */
function forgetHostedJournal(object $hosted): void
{
    $hosted->user->delete();
    Storage::disk('local')->deleteDirectory('trade-screenshots');
}

// ---------------------------------------------------------------------------
// Export
// ---------------------------------------------------------------------------

test('the export zip holds the journal data and its images', function () {
    $hosted = hostedJournal();

    $zipPath = exportJournal($this, 'friend@example.com');

    $zip = new ZipArchive;
    $zip->open($zipPath);
    $data = json_decode($zip->getFromName(JournalArchive::DATA_FILE), true);

    expect($data['format'])->toBe(JournalArchive::FORMAT)
        ->and($data['journal']['timezone'])->toBe('America/New_York')
        ->and($data['tables']['accounts'])->toHaveCount(2)
        ->and($data['tables']['account_transactions'])->toHaveCount(1)
        ->and($data['tables']['trades'])->toHaveCount(2)
        ->and($data['tables']['trade_executions'])->toHaveCount(2)
        ->and($data['tables']['trade_legs'])->toHaveCount(1)
        ->and($data['tables']['trade_notes'])->toHaveCount(1)
        ->and($data['tables']['trade_screenshots'])->toHaveCount(2)
        ->and($zip->getFromName($data['tables']['trade_screenshots'][0]['file']))->toBe('png-exit')
        ->and(json_encode($data))->not->toContain($hosted->user->journal->ingest_token_hash);
});

test('the user can be given by id', function () {
    $hosted = hostedJournal();

    exportJournal($this, (string) $hosted->user->id);

    expect(File::files($this->exportDir))->toHaveCount(1);
});

test('only that user\'s journal is exported', function () {
    hostedJournal();
    $other = User::factory()->create();
    Trade::factory()->create(['journal_id' => $other->journal->id, 'account_id' => Account::factory()->create(['journal_id' => $other->journal->id])->id]);

    $zip = new ZipArchive;
    $zip->open(exportJournal($this, 'friend@example.com'));
    $data = json_decode($zip->getFromName(JournalArchive::DATA_FILE), true);

    expect($data['tables']['trades'])->toHaveCount(2)
        ->and($data['tables']['accounts'])->toHaveCount(2);
});

test('an image missing on disk is left out with a warning', function () {
    $hosted = hostedJournal();
    Storage::disk('local')->delete($hosted->master->screenshots()->first()->path);

    $this->artisan('journal:export', ['user' => 'friend@example.com', '--path' => $this->exportDir])
        ->expectsOutputToContain('Image missing, left out')
        ->assertSuccessful();
});

test('an unknown user is an error', function () {
    $this->artisan('journal:export', ['user' => 'nobody@example.com', '--path' => $this->exportDir])
        ->expectsOutputToContain('No user with a journal')
        ->assertFailed();
});

// ---------------------------------------------------------------------------
// Import
// ---------------------------------------------------------------------------

test('the import recreates the journal under the self-hosted owner', function () {
    $hosted = hostedJournal();
    $zipPath = exportJournal($this, 'friend@example.com');
    forgetHostedJournal($hosted);

    $owner = User::factory()->create();
    $owner->journal->update(['timezone' => null]);

    $this->artisan('journal:import', ['file' => $zipPath])->assertSuccessful();

    $journal = $owner->journal->fresh();
    $master = Trade::where('uuid', $hosted->master->uuid)->sole();
    $follow = Trade::where('uuid', $hosted->follower->uuid)->sole();

    expect($journal->timezone)->toBe('America/New_York')
        ->and($journal->accounts()->pluck('name')->sort()->values()->all())->toBe(['APEX-1', 'Sim101'])
        ->and(AccountTransaction::count())->toBe(1)
        ->and($master->journal_id)->toBe($journal->id)
        ->and($master->account->name)->toBe('APEX-1')
        ->and($master->stop_price)->toEqual('5000.25')
        ->and($follow->master_trade_id)->toBe($master->id)
        ->and($master->executions()->count())->toBe(2)
        ->and($master->legs()->count())->toBe(1)
        ->and($master->notes()->sole()->created_by)->toBe($owner->id)
        ->and($master->notes()->sole()->body)->toBe('Waited for the pullback');

    $screenshots = $master->screenshots()->orderBy('id')->get();
    expect($screenshots)->toHaveCount(2)
        ->and($screenshots[0]->kind)->toBe(ScreenshotKind::Exit)
        ->and($screenshots[0]->path)->toStartWith("trade-screenshots/{$journal->id}/")
        ->and(Storage::disk('local')->get($screenshots[0]->path))->toBe('png-exit')
        ->and(Storage::disk('local')->get($screenshots[1]->path))->toBe('png-entry');
});

test('importing twice adds nothing the second time', function () {
    $hosted = hostedJournal();
    $zipPath = exportJournal($this, 'friend@example.com');
    forgetHostedJournal($hosted);
    User::factory()->create();

    $this->artisan('journal:import', ['file' => $zipPath])->assertSuccessful();
    $this->artisan('journal:import', ['file' => $zipPath])->assertSuccessful();

    expect(Trade::count())->toBe(2)
        ->and(Account::count())->toBe(2)
        ->and(AccountTransaction::count())->toBe(1)
        ->and(TradeExecution::count())->toBe(2)
        ->and(TradeScreenshot::count())->toBe(2);
});

test('an existing account with the same name is reused without its deposits', function () {
    $hosted = hostedJournal();
    $zipPath = exportJournal($this, 'friend@example.com');
    forgetHostedJournal($hosted);
    $owner = User::factory()->create();
    $existing = Account::factory()->create(['journal_id' => $owner->journal->id, 'name' => 'APEX-1']);

    $this->artisan('journal:import', ['file' => $zipPath])->assertSuccessful();

    expect(Trade::where('uuid', $hosted->master->uuid)->sole()->account_id)->toBe($existing->id)
        ->and(Account::count())->toBe(2)
        ->and(AccountTransaction::count())->toBe(0);
});

test('with several users, --email picks the journal', function () {
    $hosted = hostedJournal();
    $zipPath = exportJournal($this, 'friend@example.com');
    forgetHostedJournal($hosted);
    User::factory()->create();
    $target = User::factory()->create(['email' => 'me@example.com']);

    $this->artisan('journal:import', ['file' => $zipPath])
        ->expectsOutputToContain('pick one with --email')
        ->assertFailed();

    $this->artisan('journal:import', ['file' => $zipPath, '--email' => 'me@example.com'])->assertSuccessful();

    expect(Trade::where('journal_id', $target->journal->id)->count())->toBe(2);
});

test('with no users yet, the import says to open the journal first', function () {
    $hosted = hostedJournal();
    $zipPath = exportJournal($this, 'friend@example.com');
    forgetHostedJournal($hosted);

    $this->artisan('journal:import', ['file' => $zipPath])
        ->expectsOutputToContain('Open the journal in your browser once')
        ->assertFailed();
});

test('a file that is not an export is refused', function () {
    User::factory()->create();
    $path = tempnam(sys_get_temp_dir(), 'not-a-zip-');
    file_put_contents($path, 'hello');

    $this->artisan('journal:import', ['file' => $path])
        ->expectsOutputToContain('Couldn\'t open that zip')
        ->assertFailed();
});
