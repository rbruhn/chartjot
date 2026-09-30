<?php

use Illuminate\Support\Facades\Blade;

// Rendering-level guards for the hand-rolled chart components. Layout itself
// is verified in a browser; these pin the markup the layout depends on.

test('line chart y-axis sizes itself to its widest label', function () {
    // A seven-figure balance used to overflow the fixed 80px axis box.
    $html = Blade::render('<x-stats.line-chart label="Balance" :zero-baseline="false" :points="$points" />', [
        'points' => [
            ['date' => '2026-03-02', 'value' => 1241376.0],
            ['date' => '2026-03-03', 'value' => 1251500.0],
        ],
    ]);

    expect($html)->toContain('aria-hidden="true">$1,251,500.00</span>')
        ->and($html)->not->toContain('w-20');
});

test('line chart path carries a stroke attribute so it draws without the compiled class', function () {
    $html = Blade::render('<x-stats.line-chart label="P&L" :points="$points" />', [
        'points' => [['date' => '2026-03-02', 'value' => 100.0], ['date' => '2026-03-03', 'value' => -50.0]],
    ]);

    expect($html)->toMatch('/<path d="M[^"]+" fill="none" stroke="#[0-9a-f]{6}"/');
});

test('calendar heatmap scales its cells to the span shown', function () {
    $render = fn (array $days) => Blade::render('<x-stats.calendar-heatmap :daily="$daily" />', [
        'daily' => collect($days)->mapWithKeys(fn ($d) => [$d => ['pnl' => 10.0, 'count' => 1]]),
    ]);

    expect($render(['2026-03-02', '2026-03-13']))->toContain('width:28px;height:28px')
        ->and($render(['2025-03-03', '2026-03-13']))->toContain('width:12px;height:12px');
});
