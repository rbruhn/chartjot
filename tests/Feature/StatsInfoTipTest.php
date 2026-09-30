<?php

use App\Models\Account;
use App\Models\Trade;
use App\Models\TradeLeg;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Blade;
use Livewire\Livewire;

uses(LazilyRefreshDatabase::class);

/*
 * Issue #49: every statistics panel has an info icon explaining what it shows
 * and the non-obvious rules behind its numbers. The wording is the issue's,
 * pinned exactly here — it was written to avoid misreadings like "Legs carry
 * no commission" meaning "no commission was charged".
 */

const BREAKDOWN_TIP = "Uses net P&L (after commission and fees) — unlike the Runners panel's gross figures — "
    .'and the same win/loss rule as the rest of the app: breakeven counts as a loss.';

$panelTips = [
    'P&L Curve' => "Cumulative net P&L by day, using each trade's local entry time. "
        .'Reflects whichever accounts/date range are currently selected.',
    'Equity Curve' => 'Account balance including deposits and withdrawals — not just trade P&L. '
        .'If a start date is set, it opens at the real balance as of that date, not $0. '
        .'Accounts with no starting balance set are counted as $0 (already flagged separately below the chart).',
    'Daily P&L' => "One cell per trading day, shaded relative to the largest single day's P&L in the current selection, "
        .'not a fixed scale. Hover/click a day for its exact P&L and trade count.',
    'Holding Time' => 'Average time between entry and exit. Winners and losers are shown separately on purpose — '
        .'a big gap between them is usually a sign of cutting winners short or holding losers too long.',
    'Costs' => 'Total commission and fees, and what share of gross P&L they represent. '
        .'This is the only panel that shows a real, non-allocated commission figure — '
        ."trade legs (see Runners below) don't carry their own commission value at all.",
    'MAE / MFE' => 'Averages are from trades with a complete excursion only. '
        ."A trade's excursion is marked incomplete when the AddOn's price feed dropped mid-trade — "
        .'those MAE/MFE values are lower bounds, not real numbers, so they\'re excluded rather than silently understating the average. '
        ."CSV-imported trades don't have any MAE/MFE at all unless you've also imported a matching NinjaTrader Trades export (Journal Settings). "
        .'Capture ratio = net P&L ÷ MFE in dollars — how much of the best price move actually available was banked.',
    'Runners' => "Runner legs = every exit after a trade's first exit (scaling out and letting the remainder run). "
        .'Base exits = everything else, including every exit on a single-exit trade. '
        .'Runner share = runner gross P&L ÷ total gross P&L. '
        .'Shown in gross dollars, not net — the trade_legs table has no commission column at all '
        .'(only the whole trade does, shown in Costs above), so splitting a trade\'s commission across its legs '
        .'would be an invented number, not a real one.',
    'By Trade Type'          => BREAKDOWN_TIP,
    'Long vs. Short'         => BREAKDOWN_TIP,
    'By Day of Week'         => BREAKDOWN_TIP,
    'By Time of Day (entry)' => BREAKDOWN_TIP,
    'By Instrument'          => BREAKDOWN_TIP,
    'By Exit Reason'         => BREAKDOWN_TIP,
];

/** The statistics page with enough data that every panel renders. */
function infoTipPageHtml(): string
{
    $user    = User::factory()->create();
    $journal = $user->journal;
    $journal->update(['timezone' => 'UTC']);
    $account = Account::factory()->create(['journal_id' => $journal->id, 'timezone' => null, 'starting_balance' => 50000]);

    $at    = Carbon::parse('2026-03-02 14:00:00', 'UTC');
    $trade = Trade::factory()->create([
        'journal_id' => $journal->id, 'account_id' => $account->id, 'net_pnl' => 250,
        'entry_at' => $at, 'exit_at' => $at->copy()->addMinutes(5),
    ]);
    TradeLeg::factory()->create(['trade_id' => $trade->id, 'runner' => true]);

    return Livewire::actingAs($user)->test('journal.statistics', ['journal' => $journal])->html();
}

function collapse(string $text): string
{
    return trim(preg_replace('/\s+/u', ' ', $text));
}

/**
 * Every info tip on the page, keyed by the text of the heading (or tile label)
 * it sits in: ['aria-label' => …, 'text' => …].
 */
function infoTips(string $html): array
{
    $dom = new DOMDocument;
    @$dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR);
    $xpath = new DOMXPath($dom);

    $tips = [];
    foreach ($xpath->query('//*[@role="tooltip"]') as $tooltip) {
        // tooltip → the tip's wrapper span → heading
        $wrapper = $tooltip->parentNode;
        $button  = $xpath->query('.//button', $wrapper)->item(0);
        $heading = $wrapper->parentNode;

        $title = '';
        foreach ($heading->childNodes as $child) {
            if ($child !== $wrapper && ! $child instanceof DOMComment) {
                $title .= $child->textContent;
            }
        }
        // P&L / Equity Curve headings carry a "— subtitle" after the title.
        $title = collapse(explode('—', $title)[0]);

        $tips[$title] = [
            'aria-label' => $button?->getAttribute('aria-label'),
            'text'       => collapse($tooltip->textContent),
        ];
    }

    return $tips;
}

test('every panel has an info tip with the issue\'s explanation', function () use ($panelTips) {
    $tips = infoTips(infoTipPageHtml());

    foreach ($panelTips as $title => $text) {
        expect($tips)->toHaveKey($title);
        expect($tips[$title]['text'])->toBe($text, "Tip text for {$title}")
            ->and($tips[$title]['aria-label'])->toBe("About {$title}");
    }
});

test('every panel heading on the page has an info tip — none is missed', function () {
    $html = infoTipPageHtml();
    $dom  = new DOMDocument;
    @$dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR);

    $headings = iterator_to_array((new DOMXPath($dom))->query('//h3'));

    expect($headings)->toHaveCount(13);
    foreach ($headings as $h3) {
        expect((new DOMXPath($dom))->query('.//*[@role="tooltip"]', $h3)->length)
            ->toBe(1, 'No info tip in heading: '.collapse($h3->textContent));
    }
});

test('the Max Drawdown tile explains why it uses trade P&L, not the balance', function () {
    $tips = infoTips(infoTipPageHtml());

    expect($tips)->toHaveKey('Max Drawdown')
        ->and($tips['Max Drawdown']['text'])->toBe(
            'Largest peak-to-trough drop in cumulative net trade P&L, trade by trade, starting from $0. '
            .'It uses trade P&L rather than the account balance on purpose: on the balance-based Equity Curve, '
            .'a withdrawal or payout would register as a fake drawdown.'
        );
});

test('the Runners and MAE/MFE tips keep their emphasis markup', function () {
    $html = infoTipPageHtml();

    expect($html)->toContain('<strong>Shown in gross dollars, not net</strong>')
        ->and($html)->toContain('<code')
        ->and($html)->toContain('trade_legs</code>')
        ->and($html)->toContain('<em>complete</em>');
});

test('the footer captions stay alongside the tips', function () {
    expect(infoTipPageHtml())->toContain('Legs carry no commission.')
        ->toContain('with a complete excursion.');
});

// ---------------------------------------------------------------------------
// The component: hover, click/tap, focus, and ways to dismiss
// ---------------------------------------------------------------------------

test('the info tip opens on hover, focus and click, and closes on escape, blur and click-away', function () {
    $html = Blade::render('<x-stats.info-tip label="About Runners">Gross, not net.</x-stats.info-tip>');

    expect($html)
        ->toContain('<button type="button"')
        ->toContain('aria-label="About Runners"')
        ->toContain(':aria-describedby="$id(\'info-tip\')"')
        ->toContain(':id="$id(\'info-tip\')" role="tooltip"')
        ->toContain('@mouseenter="open = true"')
        ->toContain('@focus="open = true"')
        ->toContain('@click="pinned = ! pinned; open = pinned"')
        ->toContain('@mouseleave="open = pinned"')
        ->toContain('@blur="open = pinned = false"')
        ->toContain('@keydown.escape.window="open = pinned = false"')
        ->toContain('@click.outside=')
        ->toContain('x-anchor.fixed')   // fixed, so an overflow-hidden card can't clip it
        ->not->toContain('x-teleport')    // teleported bubbles break on Livewire re-render
        ->toContain('Gross, not net.');

    // Themed through Tailwind, never hardcoded colours.
    expect($html)->toContain('dark:bg-gray-800')
        ->not->toMatch('/style="[^"]*(color|background)/');
});
