<?php

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

/**
 * Load a Volt page and pull out its component snapshot, so the test can
 * replay the component's action through the real /livewire/update endpoint
 * (the path a browser — or an abuser — actually submits through).
 */
function voltSnapshot(string $uri, string $component): string
{
    $html = test()->get($uri)->assertOk()->getContent();

    preg_match_all('/wire:snapshot="([^"]+)"/', $html, $matches);

    foreach ($matches[1] as $encoded) {
        $snapshot = html_entity_decode($encoded, ENT_QUOTES);
        if ((json_decode($snapshot, true)['memo']['name'] ?? null) === $component) {
            return $snapshot;
        }
    }

    throw new RuntimeException("No snapshot for {$component} on {$uri}");
}

function callVoltAction(string $snapshot, array $updates, string $method)
{
    return test()->withHeaders(['X-Livewire' => 'true'])->postJson('/livewire/update', [
        'components' => [[
            'snapshot' => $snapshot,
            'updates'  => $updates,
            'calls'    => [['path' => '', 'method' => $method, 'params' => []]],
        ]],
    ]);
}

test('the register page is throttled after 6 requests a minute', function () {
    for ($i = 0; $i < 6; $i++) {
        $this->get('/register')->assertOk();
    }

    $this->get('/register')->assertStatus(429);
});

test('forgot-password submissions through Livewire are throttled', function () {
    Notification::fake();

    // The page load counts as 1 of the 6; submissions must share that
    // budget, otherwise one GET unlocks unlimited reset emails.
    $snapshot = voltSnapshot('/forgot-password', 'pages.auth.forgot-password');

    for ($i = 0; $i < 5; $i++) {
        callVoltAction($snapshot, ['email' => "victim{$i}@example.com"], 'sendPasswordResetLink')
            ->assertOk();
    }

    callVoltAction($snapshot, ['email' => 'victim-next@example.com'], 'sendPasswordResetLink')
        ->assertStatus(429);
});
