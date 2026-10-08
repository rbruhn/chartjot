<?php

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

test('registration screen can be rendered', function () {
    $this->get('/register')
        ->assertOk()
        ->assertSeeVolt('pages.auth.register');
});

test('new users are placed in pending state and redirected to the approval waiting page', function () {
    Volt::test('pages.auth.register')
        ->set('name', 'Test User')
        ->set('email', 'test@example.com')
        ->set('password', 'password')
        ->set('password_confirmation', 'password')
        ->call('register')
        ->assertHasNoErrors()
        ->assertRedirect(route('register.pending', absolute: false));

    $this->assertGuest();

    expect(User::where('email', 'test@example.com')->value('status'))
        ->toBe(UserStatus::Pending);
});

test('a journal is created for the new pending user', function () {
    Volt::test('pages.auth.register')
        ->set('name', 'Test User')
        ->set('email', 'test@example.com')
        ->set('password', 'password')
        ->set('password_confirmation', 'password')
        ->call('register');

    $user = User::where('email', 'test@example.com')->firstOrFail();

    expect($user->journal)->not->toBeNull()
        ->and($user->journal->user_id)->toBe($user->id);
});

// ---------------------------------------------------------------------------
// CHARTJOT_REGISTRATION (#117)
// ---------------------------------------------------------------------------

test('the login page links to registration when it is open', function () {
    $this->get('/login')->assertOk()->assertSee('Create an account');
});

test('with registration closed, the registration page is not found', function () {
    config(['chartjot.registration' => false]);

    $this->get('/register')->assertNotFound();
});

test('with registration closed, the form can\'t be submitted either', function () {
    // The form posts to /livewire/update, so a page opened before closing must not still work.
    config(['chartjot.registration' => false]);

    Volt::test('pages.auth.register')
        ->set('name', 'Test User')
        ->set('email', 'test@example.com')
        ->set('password', 'password')
        ->set('password_confirmation', 'password')
        ->call('register')
        ->assertNotFound();

    expect(User::where('email', 'test@example.com')->exists())->toBeFalse();
});

test('with registration closed, the login page has no Create an account link', function () {
    config(['chartjot.registration' => false]);

    $this->get('/login')
        ->assertOk()
        ->assertSee('Log in')
        ->assertDontSee('Create an account')
        ->assertDontSee(route('register'));
});

test('with registration closed, existing users still log in and pending ones see their page', function () {
    config(['chartjot.registration' => false]);
    $user = User::factory()->create();

    Volt::test('pages.auth.login')
        ->set('form.email', $user->email)
        ->set('form.password', 'password')
        ->call('login')
        ->assertHasNoErrors()
        ->assertRedirect(route('journal.index', absolute: false));

    auth()->logout();
    $this->get(route('register.pending'))->assertOk();
});
