<?php

use App\Mail\NewUserRegistered;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(LazilyRefreshDatabase::class);

test('creating a user creates a private journal with a hashed intake token', function () {
    $user = User::factory()->create();

    expect($user->journal)->not->toBeNull()
        ->and($user->journal->user_id)->toBe($user->id)
        ->and($user->journal->ingest_token_hash)->not->toBeEmpty()
        ->and($user->journal->ingest_token_hash)->toStartWith('$2');
});

test('pending users are logged out and redirected to the pending approval page', function () {
    $user = User::factory()->pending()->create();

    $this->actingAs($user)
        ->get(route('journal.index'))
        ->assertRedirect(route('register.pending'));

    $this->assertGuest();
});

test('active users can view only their own journal', function () {
    $user  = User::factory()->create();
    $other = User::factory()->create();

    $this->actingAs($user)
        ->get(route('journal.index'))
        ->assertOk()
        ->assertSee($user->journal->name);

    expect($user->can('view', $user->journal))->toBeTrue()
        ->and($other->can('view', $user->journal))->toBeFalse()
        ->and($other->can('update', $user->journal))->toBeFalse();
});

test('admin users get their own journal like any other user', function () {
    $admin = User::factory()->admin()->create();

    expect($admin->journal)->not->toBeNull()
        ->and($admin->journal->user_id)->toBe($admin->id);
});

test('admin users can visit the journal index like any other user', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get(route('journal.index'))
        ->assertOk();
});

test('registration notification goes only to ADMIN_EMAIL when configured, not every admin', function () {
    config(['mail.admin_notification_email' => 'bruhnrp@gmail.com']);

    User::factory()->admin()->create(['email' => 'admin1@example.com']);
    User::factory()->admin()->create(['email' => 'admin2@example.com']);

    Mail::fake();

    User::factory()->create();

    Mail::assertSent(NewUserRegistered::class, function ($mail) {
        return $mail->hasTo('bruhnrp@gmail.com')
            && ! $mail->hasTo('admin1@example.com')
            && ! $mail->hasTo('admin2@example.com');
    });
});

test('registration notification falls back to every admin when ADMIN_EMAIL is not configured', function () {
    config(['mail.admin_notification_email' => null]);

    User::factory()->admin()->create(['email' => 'admin1@example.com']);
    User::factory()->admin()->create(['email' => 'admin2@example.com']);

    Mail::fake();

    User::factory()->create();

    Mail::assertSent(NewUserRegistered::class, function ($mail) {
        return $mail->hasTo('admin1@example.com') && $mail->hasTo('admin2@example.com');
    });
});
