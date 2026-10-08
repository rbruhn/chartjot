<?php

use App\Livewire\Actions\Logout;
use App\Mail\NewUserRegistered;
use App\Models\Account;
use App\Models\Trade;
use App\Models\User;
use App\Support\SelfHostedPassword;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Mail;

uses(LazilyRefreshDatabase::class);

// Self-hosted mode (issue #102): one trader, no login, no friends or admin.

beforeEach(function () {
    config(['chartjot.self_hosted' => true]);
});

// ---------------------------------------------------------------------------
// Signing in as the owner
// ---------------------------------------------------------------------------

test('a fresh install creates the owner and their journal on the first visit', function () {
    config(['chartjot.owner.name' => 'Pat']);

    $this->get('/journal')->assertOk()->assertSee("Pat's Trade Journal");

    $owner = User::sole();
    expect($owner->name)->toBe('Pat')
        ->and($owner->isActive())->toBeTrue()
        ->and($owner->isAdmin())->toBeFalse()
        ->and($owner->journal)->not->toBeNull();
    $this->assertAuthenticatedAs($owner);
});

test('later visits reuse the same owner', function () {
    $this->get('/journal')->assertOk();
    auth()->guard('web')->logout();
    $this->get('/journal/statistics')->assertOk();

    expect(User::count())->toBe(1);
});

test('an existing journal keeps its first active user as the owner', function () {
    User::factory()->pending()->create();
    $existing = User::factory()->create();
    User::factory()->create();

    $this->get('/journal')->assertOk();

    $this->assertAuthenticatedAs($existing);
    expect(User::count())->toBe(3);
});

test('creating the owner sends no registration email', function () {
    Mail::fake();
    config(['mail.admin_notification_email' => 'admin@example.com']);

    $this->get('/journal')->assertOk();

    Mail::assertNothingSent();
    Mail::assertNotQueued(NewUserRegistered::class);
});

// ---------------------------------------------------------------------------
// Multi-user pages are gone
// ---------------------------------------------------------------------------

test('login, registration, friends and admin pages are not found', function (string $url) {
    $this->get($url)->assertNotFound();
})->with([
    '/login',
    '/register',
    '/register/pending',
    '/forgot-password',
    '/reset-password/some-token',
    '/verify-email',
    '/confirm-password',
    '/friends',
    '/admin/users',
]);

test('the shared trade page is not found', function () {
    $this->get('/journal')->assertOk();
    $owner = User::sole();
    $account = Account::factory()->create(['journal_id' => $owner->journal->id]);
    $trade   = Trade::factory()->create(['journal_id' => $owner->journal->id, 'account_id' => $account->id]);

    $this->get(route('trades.shared', $trade))->assertNotFound();
});

test('the navigation has no friends, admin or log out links', function () {
    User::factory()->admin()->create();

    $this->get('/journal')
        ->assertOk()
        ->assertDontSee(route('friends.index'))
        ->assertDontSee(route('admin.users'))
        ->assertDontSee('/horizon')
        ->assertDontSee('Log Out');
});

test('the profile page only edits the name and email', function () {
    $this->get('/profile')
        ->assertOk()
        ->assertSeeLivewire('profile.update-profile-information-form')
        ->assertDontSeeLivewire('profile.update-password-form')
        ->assertDontSeeLivewire('profile.delete-user-form');
});

test('the owner cannot invite anyone to a trade', function () {
    $this->get('/journal')->assertOk();
    $owner = User::sole();
    $account = Account::factory()->create(['journal_id' => $owner->journal->id]);
    $trade   = Trade::factory()->create(['journal_id' => $owner->journal->id, 'account_id' => $account->id]);

    expect($owner->can('invite', $trade))->toBeFalse();
});

// ---------------------------------------------------------------------------
// Optional password
// ---------------------------------------------------------------------------

/** Unlocks the journal and returns the unlock cookie's value. */
function unlockJournal($test, string $password = 'open sesame'): string
{
    $response = $test->post('/unlock', ['password' => $password]);
    $response->assertRedirect('/journal')->assertCookie(SelfHostedPassword::COOKIE);

    return $response->getCookie(SelfHostedPassword::COOKIE)->getValue();
}

test('with a password, a new browser is sent to the unlock page', function () {
    config(['chartjot.password' => 'open sesame']);

    $this->get('/journal')->assertRedirect('/unlock');
    $this->get('/unlock')->assertOk()->assertSee('Unlock');

    $this->assertGuest();
    expect(User::count())->toBe(0);
});

test('a wrong password keeps the journal locked', function () {
    config(['chartjot.password' => 'open sesame']);

    $this->post('/unlock', ['password' => 'nope'])
        ->assertSessionHasErrors('password')
        ->assertCookieMissing(SelfHostedPassword::COOKIE);

    $this->get('/journal')->assertRedirect('/unlock');
});

test('the right password unlocks the journal and returns to the page asked for', function () {
    config(['chartjot.password' => 'open sesame']);

    $this->get('/accounts')->assertRedirect('/unlock');
    $this->post('/unlock', ['password' => 'open sesame'])
        ->assertRedirect('/accounts')
        ->assertCookie(SelfHostedPassword::COOKIE);
});

test('an unlocked browser stays signed in as the owner', function () {
    config(['chartjot.password' => 'open sesame']);
    $cookie = unlockJournal($this);

    $this->withCookie(SelfHostedPassword::COOKIE, $cookie)->get('/journal')
        ->assertOk()
        ->assertSee('Log Out');

    $this->assertAuthenticatedAs(User::sole());
});

test('changing the password locks every browser again', function () {
    config(['chartjot.password' => 'open sesame']);
    $cookie = unlockJournal($this);
    $this->withCookie(SelfHostedPassword::COOKIE, $cookie)->get('/journal')->assertOk();

    config(['chartjot.password' => 'something new']);

    $this->withCookie(SelfHostedPassword::COOKIE, $cookie)->get('/journal')->assertRedirect('/unlock');
    $this->assertGuest();
});

test('logging out locks the browser', function () {
    config(['chartjot.password' => 'open sesame']);

    app(Logout::class)();

    expect(Cookie::hasQueued(SelfHostedPassword::COOKIE))->toBeTrue()
        ->and(Cookie::queued(SelfHostedPassword::COOKIE)->getExpiresTime())->toBeLessThan(time());
});

test('the unlock page does not exist without a password', function () {
    $this->get('/unlock')->assertNotFound();
    $this->post('/unlock', ['password' => 'anything'])->assertNotFound();
});

test('the unlock page does not exist when self-hosted mode is off', function () {
    config(['chartjot.self_hosted' => false, 'chartjot.password' => 'open sesame']);

    $this->get('/unlock')->assertNotFound();
});

// ---------------------------------------------------------------------------
// What stays the same
// ---------------------------------------------------------------------------

test('the AddOn API still needs the journal token', function () {
    $this->postJson('/api/v1/trades', [])->assertUnauthorized();

    expect(User::count())->toBe(0);
});

test('with self-hosted mode off, guests are sent to the login page', function () {
    config(['chartjot.self_hosted' => false]);

    $this->get('/journal')->assertRedirect('/login');
    $this->get('/login')->assertOk();

    expect(User::count())->toBe(0);
});
