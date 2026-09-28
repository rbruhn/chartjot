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
