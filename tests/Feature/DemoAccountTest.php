<?php

use App\Enums\ScreenshotKind;
use App\Mail\NewUserRegistered;
use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\Journal;
use App\Models\Trade;
use App\Models\TradeComment;
use App\Models\TradeExecution;
use App\Models\TradeNote;
use App\Models\TradeScreenshot;
use App\Models\User;
use App\Support\JournalArchive;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(LazilyRefreshDatabase::class);

// #115: the read-only demo account. Everything is visible; every create, edit
// or delete is refused with a 403 and changes nothing.

beforeEach(function () {
    Storage::fake('local');
});

/** The demo account with one account, a deposit, an empty account, two trades, a note and an image. */
function demoJournal(): object
{
    $demo = User::factory()->demo()->create(['name' => 'Demo Trader']);
    $demo->journal->update(['timezone' => 'America/New_York']);
    $account = Account::factory()->create(['journal_id' => $demo->journal->id, 'name' => 'FUNDED-01', 'account_type' => 'funded']);
    $empty   = Account::factory()->create(['journal_id' => $demo->journal->id, 'name' => 'EMPTY-01']);
    $tx      = AccountTransaction::factory()->create(['account_id' => $account->id]);
    $trade   = Trade::factory()->create(['journal_id' => $demo->journal->id, 'account_id' => $account->id, 'entry_at' => now()->subHour(), 'exit_at' => now()->subMinutes(30)]);
    Trade::factory()->create(['journal_id' => $demo->journal->id, 'account_id' => $account->id, 'source' => 'csv_import', 'entry_at' => now()->subHours(2), 'exit_at' => now()->subHours(2)->addMinutes(5)]);
    TradeExecution::factory()->create(['trade_id' => $trade->id]);
    $note = TradeNote::factory()->create(['trade_id' => $trade->id, 'created_by' => $demo->id]);
    $path = "trade-screenshots/{$demo->journal->id}/{$trade->uuid}.png";
    Storage::disk('local')->put($path, 'image');
    $shot = TradeScreenshot::factory()->create(['trade_id' => $trade->id, 'kind' => ScreenshotKind::Exit, 'disk' => 'local', 'path' => $path]);

    $friend = User::factory()->create(['email' => 'friend@example.com']);

    return (object) compact('demo', 'account', 'empty', 'tx', 'trade', 'note', 'shot', 'friend');
}

/** Everything a write could change, to compare before and after. */
function demoSnapshot(): array
{
    $tables = ['users', 'journals', 'accounts', 'account_transactions', 'trades', 'trade_executions', 'trade_legs',
        'trade_notes', 'trade_screenshots', 'trade_comments', 'friendships', 'jobs'];

    return [
        // remember_token is left out: signing out rotates it, and Profile → Delete Account signs out first.
        'rows'  => collect($tables)->mapWithKeys(fn ($t) => [$t => DB::table($t)->get()->map(fn ($r) => array_diff_key((array) $r, ['remember_token' => 1]))->all()])->all(),
        'files' => Storage::disk('local')->allFiles(),
    ];
}

// ---------------------------------------------------------------------------
// Getting in
// ---------------------------------------------------------------------------

test('View the demo signs in as the demo account', function () {
    $d = demoJournal();

    $this->get('/demo')->assertRedirect(route('journal.index'));

    $this->assertAuthenticatedAs($d->demo);
});

test('the login page links to the demo only when there is one', function () {
    $this->get('/login')->assertOk()->assertDontSee('View the demo');

    demoJournal();

    $this->get('/login')->assertOk()->assertSee('View the demo')->assertSee(route('demo'));
});

test('there is no demo without a demo account, or in self-hosted mode', function () {
    $this->get('/demo')->assertNotFound();

    demoJournal();
    config(['chartjot.self_hosted' => true]);
    $this->get('/demo')->assertNotFound();
});

test('a signed-in trader is not switched to the demo', function () {
    demoJournal();
    $trader = User::factory()->create();

    $this->actingAs($trader)->get('/demo')->assertRedirect();

    $this->assertAuthenticatedAs($trader);
});

// ---------------------------------------------------------------------------
// Reading works
// ---------------------------------------------------------------------------

test('the demo can view every page', function (string $url) {
    $d = demoJournal();

    $this->actingAs($d->demo)->get($url)->assertOk();
})->with(['/journal', '/journal/statistics', '/accounts', '/journal/settings', '/profile', '/friends']);

test('the demo can open trades, filter and see images', function () {
    $d = demoJournal();

    Livewire::actingAs($d->demo)
        ->test('journal.trade-journal', ['journal' => $d->demo->journal])
        ->set('search', 'ES')
        ->set('dateFrom', now()->subDay()->toDateString())
        ->call('selectTrade', $d->trade->uuid)
        ->assertOk();

    $this->actingAs($d->demo)
        ->get(route('journal.screenshot', [$d->trade, $d->shot]))
        ->assertOk();
});

// ---------------------------------------------------------------------------
// Every write is refused and changes nothing
// ---------------------------------------------------------------------------

test('the demo cannot change anything', function (Closure $attempt) {
    $d      = demoJournal();
    $before = demoSnapshot();

    $attempt($this, $d)->assertForbidden();

    expect(demoSnapshot())->toEqual($before);
})->with([
    'add an account' => fn ($test, $d) => Livewire::actingAs($d->demo)->test('journal.accounts', ['journal' => $d->demo->journal])
        ->call('startCreate')->set('name', 'NEW-01')->call('save'),
    'edit an account' => fn ($test, $d) => Livewire::actingAs($d->demo)->test('journal.accounts', ['journal' => $d->demo->journal])
        ->call('startEdit', $d->account->id)->set('name', 'RENAMED')->call('save'),
    'delete an account' => fn ($test, $d) => Livewire::actingAs($d->demo)->test('journal.accounts', ['journal' => $d->demo->journal])
        ->call('delete', $d->empty->id),
    'clear trades' => fn ($test, $d) => Livewire::actingAs($d->demo)->test('journal.accounts', ['journal' => $d->demo->journal])
        ->call('clearTrades', $d->account->id),
    'delete all accounts' => fn ($test, $d) => Livewire::actingAs($d->demo)->test('journal.accounts', ['journal' => $d->demo->journal])
        ->call('deleteAll'),
    'merge accounts' => fn ($test, $d) => Livewire::actingAs($d->demo)->test('journal.accounts', ['journal' => $d->demo->journal])
        ->call('startMerge', $d->empty->id)->set('mergeTargetId', (string) $d->account->id)->call('merge'),
    'add a deposit' => fn ($test, $d) => Livewire::actingAs($d->demo)->test('journal.accounts', ['journal' => $d->demo->journal])
        ->call('toggleTransactions', $d->account->id)->set('txType', 'deposit')->set('txAmount', '100')->set('txDate', now()->toDateString())->call('addTransaction'),
    'delete a deposit' => fn ($test, $d) => Livewire::actingAs($d->demo)->test('journal.accounts', ['journal' => $d->demo->journal])
        ->call('toggleTransactions', $d->account->id)->call('deleteTransaction', $d->tx->id),
    'add a note' => fn ($test, $d) => Livewire::actingAs($d->demo)->test('journal.trade-journal', ['journal' => $d->demo->journal])
        ->call('selectTrade', $d->trade->uuid)->set('newNoteBody', 'hello')->call('addNote'),
    'delete a note' => fn ($test, $d) => Livewire::actingAs($d->demo)->test('journal.trade-journal', ['journal' => $d->demo->journal])
        ->call('selectTrade', $d->trade->uuid)->call('deleteNote', $d->note->id),
    'set the stop price' => fn ($test, $d) => Livewire::actingAs($d->demo)->test('journal.trade-journal', ['journal' => $d->demo->journal])
        ->call('selectTrade', $d->trade->uuid)->set('stopPriceForm', '5000.25')->call('saveStopPrice'),
    'upload an image' => fn ($test, $d) => Livewire::actingAs($d->demo)->test('journal.trade-journal', ['journal' => $d->demo->journal])
        ->call('selectTrade', $d->trade->uuid)->set('screenshotUpload', UploadedFile::fake()->image('chart.png'))->call('uploadScreenshot'),
    'delete an image' => fn ($test, $d) => Livewire::actingAs($d->demo)->test('journal.trade-journal', ['journal' => $d->demo->journal])
        ->call('selectTrade', $d->trade->uuid)->call('deleteScreenshot', $d->shot->id),
    'delete a trade' => fn ($test, $d) => Livewire::actingAs($d->demo)->test('journal.trade-journal', ['journal' => $d->demo->journal])
        ->call('deleteTrade', $d->trade->uuid),
    'comment on a trade' => fn ($test, $d) => Livewire::actingAs($d->demo)->test('journal.trade-journal', ['journal' => $d->demo->journal])
        ->call('selectTrade', $d->trade->uuid)->set('commentBody', 'hi')->call('postComment'),
    'save journal settings' => fn ($test, $d) => $test->actingAs($d->demo)
        ->put(route('journal.settings.update'), ['name' => 'Mine now', 'timezone' => 'UTC']),
    'generate a new intake token' => fn ($test, $d) => $test->actingAs($d->demo)
        ->post(route('journal.settings.token.rotate')),
    'change the name' => fn ($test, $d) => Livewire::actingAs($d->demo)->test('profile.update-profile-information-form')
        ->set('name', 'Someone')->set('email', 'someone@example.com')->call('updateProfileInformation'),
    'change the password' => fn ($test, $d) => Livewire::actingAs($d->demo)->test('profile.update-password-form')
        ->set('current_password', 'password')->set('password', 'new-password-123')->set('password_confirmation', 'new-password-123')->call('updatePassword'),
    'delete the account' => fn ($test, $d) => Livewire::actingAs($d->demo)->test('profile.delete-user-form')
        ->set('password', 'password')->call('deleteUser'),
    'import a CSV' => fn ($test, $d) => Livewire::actingAs($d->demo)->test('journal.csv-import', ['journal' => $d->demo->journal])
        ->set('csvFile', UploadedFile::fake()->createWithContent('executions.csv', "Instrument,Action\nES,Buy\n")),
    'import MAE/MFE' => fn ($test, $d) => Livewire::actingAs($d->demo)->test('journal.trades-mae-mfe-import', ['journal' => $d->demo->journal])
        ->set('csvFile', UploadedFile::fake()->createWithContent('Trades.csv', file_get_contents(base_path('tests/Fixtures/ninjatrader/Trades.csv')))),
    'send a friend request' => fn ($test, $d) => Livewire::actingAs($d->demo)->test('friends.manage')
        ->set('searchEmail', $d->friend->email)->call('sendRequest'),
]);

test('signing out of the demo still works', function () {
    $d = demoJournal();

    Livewire::actingAs($d->demo)->test('layout.navigation')->call('logout');

    $this->assertGuest();
});

test('other traders can still make changes', function () {
    $trader = User::factory()->create();
    demoJournal();

    Livewire::actingAs($trader)
        ->test('journal.accounts', ['journal' => $trader->journal])
        ->call('startCreate')->set('name', 'MINE-01')->call('save')
        ->assertHasNoErrors();

    expect(Account::where('journal_id', $trader->journal->id)->pluck('name')->all())->toBe(['MINE-01']);
});

// ---------------------------------------------------------------------------
// demo:install
// ---------------------------------------------------------------------------

test('demo:install creates the demo account and loads the journal, and resets it when run again', function () {
    $source  = User::factory()->create(['email' => 'source@example.com']);
    Mail::fake();
    $account = Account::factory()->create(['journal_id' => $source->journal->id]);
    Trade::factory()->count(3)->create(['journal_id' => $source->journal->id, 'account_id' => $account->id]);
    $dir = sys_get_temp_dir().'/chartjot-demo-test-'.uniqid();
    $this->artisan('journal:export', ['user' => 'source@example.com', '--path' => $dir])->assertSuccessful();
    $zip = File::files($dir)[0]->getPathname();
    $source->delete();

    $this->artisan('demo:install', ['file' => $zip])->assertSuccessful();
    $demo = User::where('is_demo', true)->with('journal')->sole();
    Trade::where('journal_id', $demo->journal->id)->first()->update(['net_pnl' => '999.00']);
    $this->artisan('demo:install', ['file' => $zip])->assertSuccessful();

    expect(User::where('is_demo', true)->count())->toBe(1)
        ->and($demo->isActive())->toBeTrue()
        ->and($demo->journal->fresh()->name)->toBe('Demo Trade Journal')
        ->and(Trade::where('journal_id', $demo->journal->id)->count())->toBe(3)
        ->and(Trade::where('net_pnl', '999.00')->exists())->toBeFalse();
    Mail::assertNotSent(NewUserRegistered::class);

    File::deleteDirectory($dir);
});
