{{--
    Single-series line chart as inline SVG, with a crosshair + tooltip that
    snaps to the nearest point (pointer or ←/→ keys). Points are evenly
    spaced by index, i.e. one per trading day, so weekends leave no gaps.

    points: list of ['date' => 'Y-m-d', 'value' => float]
    zeroBaseline: include 0 in the y-range and draw it (P&L); off for balances.
--}}
@props(['points', 'label', 'zeroBaseline' => true])

@php
    use App\Support\Format;
    use Illuminate\Support\Carbon;

    $points = collect($points)->values();
    $n      = $points->count();
    $W = 1000; $H = 240;

    $values = $points->pluck('value');
    $min = (float) $values->min();
    $max = (float) $values->max();
    if ($zeroBaseline) { $min = min($min, 0.0); $max = max($max, 0.0); }
    if ($max == $min) { $max += 1; $min -= 1; }
    $pad = ($max - $min) * 0.06;
    $lo = $min - ($zeroBaseline && $min == 0.0 ? 0 : $pad);
    $hi = $max + $pad;

    $x = fn (int $i) => $n > 1 ? $i / ($n - 1) * $W : $W / 2;
    $y = fn (float $v) => $H - ($v - $lo) / ($hi - $lo) * $H;

    $path = $points->map(fn ($p, $i) => ($i === 0 ? 'M' : 'L').round($x($i), 2).' '.round($y((float) $p['value']), 2))->implode(' ');

    $tip = $points->map(fn ($p, $i) => [
        'date'  => Carbon::parse($p['date'])->format('D j M Y'),
        'value' => $zeroBaseline ? Format::pnl((float) $p['value']) : Format::money((float) $p['value']),
        'x'     => $n > 1 ? $i / ($n - 1) * 100 : 50,
        'y'     => $y((float) $p['value']) / $H * 100,
    ])->all();

    $pct = fn (float $v) => $y($v) / $H * 100;
    $fmt = fn (float $v) => $zeroBaseline ? Format::pnl($v) : Format::money($v);
@endphp

<div x-data="{
        pts: @js($tip),
        i: null,
        move(e) {
            const r = this.$refs.plot.getBoundingClientRect();
            const f = (e.clientX - r.left) / r.width;
            this.i = Math.max(0, Math.min(this.pts.length - 1, Math.round(f * (this.pts.length - 1))));
        },
        step(d) {
            this.i = this.i === null ? this.pts.length - 1 : Math.max(0, Math.min(this.pts.length - 1, this.i + d));
        },
    }"
    class="flex gap-2">

    {{-- Y axis: top, zero (when shown) and bottom of the data range --}}
    <div class="relative w-20 flex-shrink-0 text-right text-xs text-gray-500 dark:text-gray-400 tabular-nums" style="height:15rem">
        <span class="absolute right-0 -translate-y-1/2" style="top:{{ $pct($max) }}%">{{ $fmt($max) }}</span>
        @if ($zeroBaseline && $min < 0 && $max > 0 && abs($pct(0) - $pct($min)) > 8 && abs($pct(0) - $pct($max)) > 8)
            <span class="absolute right-0 -translate-y-1/2" style="top:{{ $pct(0) }}%">$0</span>
        @endif
        <span class="absolute right-0 -translate-y-1/2" style="top:{{ $pct($min) }}%">{{ $fmt($min) }}</span>
    </div>

    <div class="flex-1 min-w-0">
        <div x-ref="plot" class="relative cursor-crosshair focus:outline-none focus-visible:ring-1 focus-visible:ring-indigo-500 rounded" style="height:15rem"
            tabindex="0" role="img" aria-label="{{ $label }}: line chart, {{ $n }} {{ Str::plural('day', $n) }}. Use the left and right arrow keys to read values."
            @pointermove="move($event)" @pointerleave="i = null"
            @keydown.arrow-left.prevent="step(-1)" @keydown.arrow-right.prevent="step(1)" @blur="i = null">

            <svg viewBox="0 0 {{ $W }} {{ $H }}" preserveAspectRatio="none" class="absolute inset-0 w-full h-full overflow-visible" aria-hidden="true">
                @if ($zeroBaseline && $lo < 0 && $hi > 0)
                    <line x1="0" x2="{{ $W }}" y1="{{ $y(0) }}" y2="{{ $y(0) }}" vector-effect="non-scaling-stroke"
                        class="stroke-gray-300 dark:stroke-gray-600" stroke-width="1" />
                @endif
                @if ($n > 1)
                    <path d="{{ $path }}" fill="none" vector-effect="non-scaling-stroke" stroke-width="2"
                        stroke-linejoin="round" stroke-linecap="round" class="stroke-indigo-600 dark:stroke-indigo-400" />
                @endif
            </svg>

            @if ($n === 1)
                <span class="absolute w-2.5 h-2.5 -ml-[5px] -mt-[5px] rounded-full bg-indigo-600 dark:bg-indigo-400" style="left:50%;top:{{ $tip[0]['y'] }}%"></span>
            @endif

            {{-- Crosshair, marker and tooltip --}}
            <template x-if="i !== null">
                <div>
                    <div class="absolute top-0 bottom-0 w-px bg-gray-400 dark:bg-gray-500 pointer-events-none" :style="`left:${pts[i].x}%`"></div>
                    <span class="absolute w-2.5 h-2.5 -ml-[5px] -mt-[5px] rounded-full bg-indigo-600 dark:bg-indigo-400 ring-2 ring-white dark:ring-gray-900 pointer-events-none"
                        :style="`left:${pts[i].x}%;top:${pts[i].y}%`"></span>
                    <div class="absolute top-0 z-10 px-2.5 py-1.5 rounded shadow-lg text-xs whitespace-nowrap pointer-events-none bg-white dark:bg-gray-700 border border-gray-200 dark:border-gray-600"
                        :style="`left:${pts[i].x}%;transform:translateX(${pts[i].x > 70 ? 'calc(-100% - 8px)' : '8px'})`">
                        <div class="text-gray-500 dark:text-gray-400" x-text="pts[i].date"></div>
                        <div class="font-semibold text-gray-900 dark:text-gray-100 tabular-nums" x-text="pts[i].value"></div>
                    </div>
                </div>
            </template>
        </div>

        <div class="flex justify-between mt-1 text-xs text-gray-500 dark:text-gray-400">
            <span>{{ Carbon::parse($points->first()['date'])->format('j M Y') }}</span>
            @if ($n > 1)
                <span>{{ Carbon::parse($points->last()['date'])->format('j M Y') }}</span>
            @endif
        </div>
    </div>
</div>
