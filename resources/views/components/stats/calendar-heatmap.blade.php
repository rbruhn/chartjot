{{--
    Daily P&L calendar: one column per week (Mon → Sun), one cell per day.
    Green/red follows the app's profit/loss convention; three intensity steps
    per side scaled to the largest absolute day, neutral gray for a flat day.
    Hovering a cell fills the readout line, and the same numbers are in the
    daily table below, so color is never the only way to read a value.

    daily: Collection keyed by local 'Y-m-d' => ['pnl' => float, 'count' => int]
--}}
@props(['daily'])

@php
    use App\Support\Format;
    use Illuminate\Support\Carbon;

    $first = Carbon::parse($daily->keys()->first())->startOfWeek(Carbon::MONDAY);
    $last  = Carbon::parse($daily->keys()->last())->endOfWeek(Carbon::SUNDAY);
    $rangeStart = $daily->keys()->first();
    $rangeEnd   = $daily->keys()->last();
    $maxAbs = max(0.01, (float) $daily->max(fn ($d) => abs($d['pnl'])));

    $shade = function (float $pnl) use ($maxAbs): string {
        if (round($pnl, 2) == 0.0) {
            return 'bg-gray-400 dark:bg-gray-500';
        }
        $level = min(2, (int) floor(abs($pnl) / $maxAbs * 3));
        return $pnl > 0
            ? ['bg-green-200 dark:bg-green-900', 'bg-green-400 dark:bg-green-700', 'bg-green-600 dark:bg-green-500'][$level]
            : ['bg-red-200 dark:bg-red-900', 'bg-red-400 dark:bg-red-700', 'bg-red-600 dark:bg-red-500'][$level];
    };

    $weeks = [];
    for ($week = $first->copy(); $week->lte($last); $week->addWeek()) {
        $days = [];
        for ($d = 0; $d < 7; $d++) {
            $date = $week->copy()->addDays($d);
            $key  = $date->format('Y-m-d');
            $day  = $daily->get($key);
            $inRange = $key >= $rangeStart && $key <= $rangeEnd;
            $days[] = [
                'class' => ! $inRange ? 'invisible' : ($day ? $shade($day['pnl']) : 'bg-gray-100 dark:bg-gray-800'),
                'tip'   => $inRange
                    ? $date->format('D j M Y').' · '.($day
                        ? $day['count'].' '.Str::plural('trade', $day['count']).' · '.Format::pnl($day['pnl'])
                        : 'no trades')
                    : null,
            ];
        }
        $weeks[] = [
            // Label the week containing each month's 1st, plus the first column
            // when the next label is far enough away not to collide with it.
            'month' => ($sunday = $week->copy()->addDays(6))->day <= 7 || ($week->eq($first) && $sunday->day <= 21) ? $sunday->format('M') : '',
            'days'  => $days,
        ];
    }
@endphp

<div x-data="{ tip: '' }">
    <div class="overflow-x-auto pb-1">
        <div class="inline-flex gap-1.5">
            <div class="flex flex-col gap-[2px] pt-4 text-[10px] leading-3 text-gray-500 dark:text-gray-400">
                @foreach (['Mon', '', 'Wed', '', 'Fri', '', ''] as $dayLabel)
                    <span class="h-3">{{ $dayLabel }}</span>
                @endforeach
            </div>
            <div class="flex gap-[2px]" role="img" aria-label="Calendar of daily P&amp;L; the same values are in the daily table below.">
                @foreach ($weeks as $week)
                    <div class="flex flex-col gap-[2px]">
                        {{-- Absolutely positioned so a label can't widen its 12px week column --}}
                        <span class="relative h-3.5"><span class="absolute left-0 top-0 text-[10px] leading-3 text-gray-500 dark:text-gray-400 whitespace-nowrap">{{ $week['month'] }}</span></span>
                        @foreach ($week['days'] as $cell)
                            <span class="block w-3 h-3 rounded-sm {{ $cell['class'] }} {{ $cell['tip'] ? 'hover:ring-2 hover:ring-indigo-500' : '' }}"
                                @if ($cell['tip']) data-tip="{{ $cell['tip'] }}" @pointerenter="tip = $el.dataset.tip" @pointerleave="tip = ''" @endif></span>
                        @endforeach
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    <div class="flex flex-wrap items-center justify-between gap-3 mt-2 text-xs text-gray-500 dark:text-gray-400">
        <span class="min-h-[1rem] text-gray-700 dark:text-gray-300 tabular-nums" x-text="tip || 'Hover a day for its P&L.'"></span>
        <span class="flex items-center gap-1">
            Loss
            <span class="w-3 h-3 rounded-sm bg-red-600 dark:bg-red-500"></span>
            <span class="w-3 h-3 rounded-sm bg-red-400 dark:bg-red-700"></span>
            <span class="w-3 h-3 rounded-sm bg-red-200 dark:bg-red-900"></span>
            <span class="w-3 h-3 rounded-sm bg-gray-400 dark:bg-gray-500" title="Flat day"></span>
            <span class="w-3 h-3 rounded-sm bg-green-200 dark:bg-green-900"></span>
            <span class="w-3 h-3 rounded-sm bg-green-400 dark:bg-green-700"></span>
            <span class="w-3 h-3 rounded-sm bg-green-600 dark:bg-green-500"></span>
            Profit
            <span class="ml-3 w-3 h-3 rounded-sm bg-gray-100 dark:bg-gray-800 border border-gray-200 dark:border-gray-700"></span>
            No trades
        </span>
    </div>

    <details class="mt-3 text-sm">
        <summary class="cursor-pointer text-xs text-indigo-600 dark:text-indigo-400 select-none">Daily table</summary>
        <div class="mt-2 max-h-64 overflow-y-auto">
            <table class="w-full text-sm tabular-nums">
                <thead class="text-xs text-gray-500 dark:text-gray-400 text-left">
                    <tr><th class="py-1 font-semibold">Day</th><th class="py-1 font-semibold text-right">Trades</th><th class="py-1 font-semibold text-right">Net P&amp;L</th></tr>
                </thead>
                <tbody>
                    @foreach ($daily->reverse() as $date => $day)
                        <tr class="border-t border-gray-100 dark:border-gray-800">
                            <td class="py-1 text-gray-700 dark:text-gray-300">{{ Carbon::parse($date)->format('D j M Y') }}</td>
                            <td class="py-1 text-right text-gray-700 dark:text-gray-300">{{ $day['count'] }}</td>
                            <td class="py-1 text-right {{ Format::pnlClass($day['pnl']) }}">{{ Format::pnl($day['pnl']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </details>
</div>
