{{--
    One breakdown (by trade type, weekday, hour, …) as a table with inline
    bars: win rate on a 0–100% track, net P&L as a bar diverging from zero.
    The numbers are always printed, so the bars are reinforcement only.

    rows: Collection of ['label', 'count', 'wins', 'win_rate', 'net_pnl', 'avg_pnl']
    tip:  optional explanation, shown in an info tip beside the title
--}}
@props(['title', 'rows', 'tip' => null])

@php
    use App\Support\Format;
    $maxAbs = max(0.01, (float) $rows->max(fn ($r) => abs($r['net_pnl'])));
@endphp

<div class="border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 rounded-lg overflow-hidden">
    <h3 class="flex items-center gap-1.5 px-4 py-2.5 text-xs font-semibold text-gray-600 dark:text-gray-500 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700">
        {{ $title }}
        @if ($tip)
            <x-stats.info-tip :label="'About '.$title"><span class="block">{{ $tip }}</span></x-stats.info-tip>
        @endif
    </h3>
    <div class="overflow-x-auto">
        <table class="w-full text-sm tabular-nums">
            <thead class="text-xs text-gray-500 dark:text-gray-400">
                <tr>
                    <th class="px-4 py-1.5 text-left font-medium"><span class="sr-only">Group</span></th>
                    <th class="px-2 py-1.5 text-right font-medium">Trades</th>
                    <th class="px-2 py-1.5 text-left font-medium">Win rate</th>
                    <th class="px-2 py-1.5 text-right font-medium">Avg</th>
                    <th class="px-4 py-1.5 text-left font-medium">Net P&amp;L</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    @php $w = abs($row['net_pnl']) / $maxAbs * 50; @endphp
                    <tr class="border-t border-gray-100 dark:border-gray-800">
                        <td class="px-4 py-1.5 text-gray-800 dark:text-gray-200">{{ $row['label'] }}</td>
                        <td class="px-2 py-1.5 text-right text-gray-700 dark:text-gray-300">{{ $row['count'] }}</td>
                        <td class="px-2 py-1.5">
                            <div class="flex items-center gap-2">
                                <span class="w-9 text-right text-gray-700 dark:text-gray-300">{{ number_format($row['win_rate'], 0) }}%</span>
                                <span class="relative block w-12 h-1.5 rounded-full bg-gray-100 dark:bg-gray-800" aria-hidden="true">
                                    <span class="absolute inset-y-0 left-0 rounded-full bg-indigo-500 dark:bg-indigo-400" style="width:{{ $row['win_rate'] }}%"></span>
                                </span>
                            </div>
                        </td>
                        <td class="px-2 py-1.5 text-right {{ Format::pnlClass($row['avg_pnl']) }} whitespace-nowrap">{{ Format::pnl($row['avg_pnl']) }}</td>
                        <td class="px-4 py-1.5">
                            <div class="flex items-center gap-2">
                                <span class="relative block w-16 h-2 flex-shrink-0" aria-hidden="true">
                                    <span class="absolute inset-y-0 left-1/2 w-px bg-gray-300 dark:bg-gray-600"></span>
                                    @if ($row['net_pnl'] >= 0)
                                        <span class="absolute inset-y-0 left-1/2 rounded-r bg-green-500 dark:bg-green-400" style="width:{{ $w }}%"></span>
                                    @else
                                        <span class="absolute inset-y-0 right-1/2 rounded-l bg-red-500 dark:bg-red-400" style="width:{{ $w }}%"></span>
                                    @endif
                                </span>
                                <span class="{{ Format::pnlClass($row['net_pnl']) }} whitespace-nowrap">{{ Format::pnl($row['net_pnl']) }}</span>
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
