<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

test('confirm password screen can be rendered', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/confirm-password')
        ->assertSeeVolt('pages.auth.confirm-password')
        ->assertOk();
});

test('password can be confirmed', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    Volt::test('pages.auth.confirm-password')
        ->set('password', 'password')
        ->call('confirmPassword')
        ->assertRedirect('/dashboard')
        ->assertHasNoErrors();
});

test('password is not confirmed with invalid password', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    Volt::test('pages.auth.confirm-password')
        ->set('password', 'wrong-password')
        ->call('confirmPassword')
        ->assertNoRedirect()
        ->assertHasErrors('password');
});
