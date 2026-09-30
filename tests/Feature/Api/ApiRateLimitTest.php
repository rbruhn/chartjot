<?php

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

test('the trade API is rate limited per IP before token authentication runs', function () {
    // No bearer token: every request is rejected by journal.token with a 401.
    // The limiter must sit in front of it, so these still count toward the
    // limit and the request after the 60th is throttled instead of reaching
    // the bcrypt token-matching loop.
    for ($i = 0; $i < 60; $i++) {
        $this->postJson('/api/v1/trades', [])->assertStatus(401);
    }

    $this->postJson('/api/v1/trades', [])->assertStatus(429);
});

test('token authentication runs before route model binding on the notes endpoint', function () {
    // Unauthenticated callers must get a 401, not a 404 that reveals whether
    // the trade exists — binding must not run before journal.token.
    $this->postJson('/api/v1/trades/01ZZZZZZZZZZZZZZZZZZZZZZZZ/notes', [])
        ->assertStatus(401);
});
