<?php

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(LazilyRefreshDatabase::class);

test('a user can update their journal display preferences', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->put(route('journal.settings.update'), [
            'name'     => 'Chart Jot',
            'timezone' => 'America/New_York',
        ])
        ->assertRedirect(route('journal.settings.edit'));

    $this->assertDatabaseHas('journals', [
        'id'       => $user->journal->id,
        'name'     => 'Chart Jot',
        'timezone' => 'America/New_York',
    ]);
});

test('journal settings require a valid timezone', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->from(route('journal.settings.edit'))
        ->put(route('journal.settings.update'), [
            'name'     => 'Chart Jot',
            'timezone' => 'Eastern',
        ])
        ->assertRedirect(route('journal.settings.edit'))
        ->assertSessionHasErrors('timezone');
});

test('rotating an ingest token replaces the stored hash and shows the new token once', function () {
    $user    = User::factory()->create();
    $oldHash = $user->journal->ingest_token_hash;

    $response = $this->actingAs($user)
        ->post(route('journal.settings.token.rotate'));

    $response
        ->assertRedirect(route('journal.settings.edit'))
        ->assertSessionHas('journal_ingest_token');

    $token   = $response->getSession()->get('journal_ingest_token');
    $newHash = $user->journal->fresh()->ingest_token_hash;

    expect(Hash::check($token, $oldHash))->toBeFalse()
        ->and(Hash::check($token, $newHash))->toBeTrue();
});

test('new journal is created without a timezone', function () {
    $user = User::factory()->create();

    expect($user->journal->timezone)->toBeNull();
});

test('settings page shows timezone warning when timezone is not set', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('journal.settings.edit'))
        ->assertStatus(200)
        ->assertSee('timezone');
});

test('settings page does not show timezone warning after timezone is saved', function () {
    $user = User::factory()->create();
    $user->journal->update(['timezone' => 'America/New_York']);

    $this->actingAs($user)
        ->get(route('journal.settings.edit'))
        ->assertStatus(200)
        ->assertDontSee('Time zone required before importing');
});

test('csv import is disabled with a note when the journal has no accounts', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('journal.settings.edit'))
        ->assertStatus(200)
        ->assertSee('Create at least one')
        ->assertDontSee('Drop your NT8 Executions CSV here');
});

test('csv import dropzone is available once an account exists', function () {
    $user = User::factory()->create();
    \App\Models\Account::factory()->create(['journal_id' => $user->journal->id]);

    $this->actingAs($user)
        ->get(route('journal.settings.edit'))
        ->assertStatus(200)
        ->assertSee('Drop your NT8 Executions CSV here')
        ->assertDontSee('Create at least one');
});

test('the new intake token has a copy button', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->withSession(['journal_ingest_token' => 'tok_123'])
        ->get(route('journal.settings.edit'))
        ->assertOk()
        ->assertSeeHtml('x-ref="token"')
        ->assertSeeHtml('value="tok_123"')
        ->assertSeeHtml('aria-label="Copy token"')
        ->assertSeeHtml('navigator.clipboard.writeText(box.value)');
});

test('there is no copy button when no new token is shown', function () {
    $user = User::factory()->create();

    // Creating a user flashes its first token; clear it so none is shown.
    $this->actingAs($user)
        ->withSession(['journal_ingest_token' => null])
        ->get(route('journal.settings.edit'))
        ->assertOk()
        ->assertDontSeeHtml('aria-label="Copy token"');
});
