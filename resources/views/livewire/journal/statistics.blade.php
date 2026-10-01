<?php

use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\Journal;
use App\Support\Format;
use App\Support\TradeStatistics;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new class extends Component {
    public Journal $journal;

    // Empty = unbounded: statistics default to all time.
    #[Url(as: 'from')]
    public string $dateFrom = '';

    #[Url(as: 'to')]
    public string $dateTo = '';

    #[Url(as: 'accounts')]
    public ?array $selectedAccountIds = null;

    public function mount(Journal $journal): void
    {
        $this->journal = $journal;
    }

    #[Computed]
    public function accounts(): Collection
    {
        return $this->journal->accounts()->orderBy('name')->get();
    }

    /** The accounts currently in scope (null selection = all). */
    #[Computed]
    public function selectedAccounts(): Collection
    {
        return $this->selectedAccountIds === null
            ? $this->accounts
            : $this->accounts->whereIn('id', $this->selectedAccountIds)->values();
    }

    /** One query for the whole page; every statistic is derived from this Collection. */
    #[Computed]
    public function trades(): Collection
    {
        return $this->journal->trades()
            ->with(['account.journal', 'legs'])
            ->when($this->dateFrom, fn ($q) => $q->where('entry_at', '>=', Carbon::parse($this->dateFrom)->startOfDay()))
            ->when($this->dateTo,   fn ($q) => $q->where('entry_at', '<=', Carbon::parse($this->dateTo)->endOfDay()))
            ->when($this->selectedAccountIds !== null, fn ($q) => empty($this->selectedAccountIds)
                ? $q->whereRaw('0 = 1')
                : $q->whereIn('account_id', $this->selectedAccountIds)
            )
            ->orderBy('entry_at')
            ->get();
    }

    #[Computed]
    public function stats(): TradeStatistics
    {
        return new TradeStatistics($this->trades);
    }

    /**
     * Balance curve for the selected accounts, summed: starting balances +
     * deposits − withdrawals + daily net P&L. With a start date, the opening
     * balance also carries everything before it, so the curve starts at the
     * real balance rather than at the starting balance.
     */
    #[Computed]
    public function equity(): array
    {
        $accounts = $this->selectedAccounts;
        $ids      = $accounts->pluck('id');
        $from     = $this->dateFrom ? Carbon::parse($this->dateFrom)->startOfDay() : null;
        $to       = $this->dateTo ? Carbon::parse($this->dateTo)->endOfDay() : null;

        $opening = (float) $accounts->sum(fn (Account $a) => (float) $a->starting_balance);

        $flows = AccountTransaction::whereIn('account_id', $ids)
            ->when($to, fn ($q) => $q->whereDate('occurred_at', '<=', $to->toDateString()))
            ->orderBy('occurred_at')
            ->get()
            ->map(fn (AccountTransaction $t) => [
                'date'   => $t->occurred_at->format('Y-m-d'),
                'amount' => $t->type === TransactionType::Deposit ? (float) $t->amount : -(float) $t->amount,
            ]);

        if ($from) {
            [$before, $flows] = $flows->partition(fn (array $f) => $f['date'] < $from->toDateString());
            $opening += (float) $before->sum('amount');
            $opening += (float) $this->journal->trades()
                ->whereIn('account_id', $ids)
                ->where('entry_at', '<', $from)
                ->sum('net_pnl');
        }

        return [
            'opening'         => round($opening, 2),
            'points'          => TradeStatistics::equityCurve($opening, $flows->values(), $this->stats->daily()),
            'missing_balance' => $accounts->whereNull('starting_balance')->count(),
        ];
    }

    public function clearDates(): void
    {
        $this->dateFrom = '';
        $this->dateTo   = '';
    }

    /** The earliest trade's local date for the current account selection, ignoring the date filter itself. */
    #[Computed]
    public function earliestTradeDate(): ?string
    {
        $earliest = $this->journal->trades()
            ->with('account')
            ->when($this->selectedAccountIds !== null, fn ($q) => empty($this->selectedAccountIds)
                ? $q->whereRaw('0 = 1')
                : $q->whereIn('account_id', $this->selectedAccountIds)
            )
            ->oldest('entry_at')
            ->first();

        return $earliest?->entry_at_local->format('M j, Y');
    }
}; ?>

@php
    $stats  = $this->stats;
    $s      = $stats->summary();
    $tile   = 'flex-1 min-w-[9rem] px-5 py-3 border-r border-b border-gray-200 dark:border-gray-700';
    $tLabel = 'text-xs font-semibold text-gray-600 dark:text-gray-500 uppercase tracking-wider';
    $tValue = 'text-2xl font-bold mt-0.5';
    $card   = 'border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 rounded-lg';
    $cardH  = 'flex items-center gap-1.5 px-4 py-2.5 text-xs font-semibold text-gray-600 dark:text-gray-500 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700';
    $dt     = 'text-gray-600 dark:text-gray-400';
    $dd     = 'text-right font-medium text-gray-900 dark:text-gray-100 tabular-nums';

    // Info-tip wording is from issue #49; see StatsInfoTipTest before rewording.
    $breakdownTip = "Uses net P&L (after commission and fees) — unlike the Runners panel's gross figures — "
        .'and the same win/loss rule as the rest of the app: breakeven counts as a loss.';
@endphp

<div class="text-gray-900 dark:text-gray-100 pb-8">

    {{-- ── Filter bar (same controls as the journal page) ── --}}
    <div class="flex flex-wrap items-end gap-x-5 gap-y-3 px-6 py-4 bg-gray-50 dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700">
        <div class="flex flex-col gap-1">
            <label class="text-xs font-semibold text-gray-600 dark:text-gray-500 uppercase tracking-wider">From</label>
            <input type="date" wire:model.live="dateFrom"
                class="bg-gray-100 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 text-gray-900 dark:text-gray-100 text-sm rounded px-2 py-1 focus:outline-none focus:ring-1 focus:ring-indigo-500">
        </div>
        <div class="flex flex-col gap-1">
            <label class="text-xs font-semibold text-gray-600 dark:text-gray-500 uppercase tracking-wider">To</label>
            <input type="date" wire:model.live="dateTo"
                class="bg-gray-100 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 text-gray-900 dark:text-gray-100 text-sm rounded px-2 py-1 focus:outline-none focus:ring-1 focus:ring-indigo-500">
        </div>
        <x-account-filter :accounts="$this->accounts" />
        <div class="flex flex-col gap-1">
            <span class="text-xs opacity-0 leading-none select-none">&nbsp;</span>
            @if ($dateFrom !== '' || $dateTo !== '')
                <button type="button" wire:click="clearDates" class="text-sm text-indigo-600 dark:text-indigo-400 hover:underline py-1">All time</button>
            @else
                <span class="text-sm text-gray-500 dark:text-gray-400 py-1">All time{{ $this->earliestTradeDate ? ' — since '.$this->earliestTradeDate : '' }}</span>
            @endif
        </div>
    </div>

    @if ($stats->isEmpty())
        <div class="mx-6 mt-6 {{ $card }} px-6 py-10 text-center text-sm text-gray-500 dark:text-gray-400">
            No trades match these filters.
        </div>
    @else

    {{-- ── Headline tiles ── --}}
    <div class="mx-6 mt-4 {{ $card }} overflow-hidden">
        <div class="flex flex-wrap -mr-px -mb-px">
            <div class="{{ $tile }}">
                <div class="{{ $tLabel }}">Net P&amp;L</div>
                <div class="{{ $tValue }} {{ Format::pnlClass($s['net_pnl']) }}">{{ Format::pnl($s['net_pnl']) }}</div>
            </div>
            <div class="{{ $tile }}">
                <div class="{{ $tLabel }}">Trades</div>
                <div class="{{ $tValue }}">{{ $s['total'] }}</div>
                <div class="text-xs text-gray-500 dark:text-gray-400">{{ $s['wins'] }} W · {{ $s['losses'] }} L</div>
            </div>
            <div class="{{ $tile }}">
                <div class="{{ $tLabel }}">Win Rate</div>
                <div class="{{ $tValue }}">{{ number_format($s['win_rate'], 0) }}%</div>
                <div class="text-xs text-gray-500 dark:text-gray-400">breakeven counts as a loss</div>
            </div>
            <div class="{{ $tile }}">
                <div class="{{ $tLabel }}">Expectancy</div>
                <div class="{{ $tValue }} {{ Format::pnlClass($s['expectancy']) }}">{{ Format::pnl($s['expectancy']) }}</div>
                <div class="text-xs text-gray-500 dark:text-gray-400">per trade</div>
            </div>
            <div class="{{ $tile }}">
                <div class="{{ $tLabel }}">Profit Factor</div>
                <div class="{{ $tValue }}">{{ $s['profit_factor'] === null ? '—' : number_format($s['profit_factor'], 2) }}</div>
                <div class="text-xs text-gray-500 dark:text-gray-400">{{ $s['profit_factor'] === null ? 'no losing dollars' : 'gross win ÷ gross loss' }}</div>
            </div>
            <div class="{{ $tile }}">
                <div class="{{ $tLabel }} flex items-center gap-1.5">
                    Max Drawdown
                    <x-stats.info-tip label="About Max Drawdown">
                        <span class="block">Largest peak-to-trough drop in cumulative net trade P&amp;L, trade by trade, starting from $0.</span>
                        <span class="block">It uses trade P&amp;L rather than the account balance on purpose: on the balance-based Equity Curve,
                            a withdrawal or payout would register as a fake drawdown.</span>
                    </x-stats.info-tip>
                </div>
                <div class="{{ $tValue }} {{ $s['max_drawdown'] > 0 ? 'text-red-600 dark:text-red-400' : '' }}">{{ Format::money($s['max_drawdown'] > 0 ? -$s['max_drawdown'] : 0.0) }}</div>
                <div class="text-xs text-gray-500 dark:text-gray-400">peak to trough, trade P&amp;L</div>
            </div>
        </div>
        <div class="flex flex-wrap -mr-px -mb-px">
            <div class="{{ $tile }}">
                <div class="{{ $tLabel }}">Avg Win</div>
                <div class="{{ $tValue }} text-green-600 dark:text-green-400">{{ Format::pnl($s['avg_win']) }}</div>
            </div>
            <div class="{{ $tile }}">
                <div class="{{ $tLabel }}">Avg Loss</div>
                <div class="{{ $tValue }} text-red-600 dark:text-red-400">{{ Format::pnl($s['avg_loss']) }}</div>
            </div>
            <div class="{{ $tile }}">
                <div class="{{ $tLabel }}">Largest Win</div>
                <div class="{{ $tValue }} text-green-600 dark:text-green-400">{{ Format::pnl($s['largest_win']) }}</div>
            </div>
            <div class="{{ $tile }}">
                <div class="{{ $tLabel }}">Largest Loss</div>
                <div class="{{ $tValue }} text-red-600 dark:text-red-400">{{ Format::pnl($s['largest_loss']) }}</div>
            </div>
            <div class="{{ $tile }}">
                <div class="{{ $tLabel }}">Win Streak</div>
                <div class="{{ $tValue }}">{{ $s['max_win_streak'] }}</div>
                <div class="text-xs text-gray-500 dark:text-gray-400">max consecutive</div>
            </div>
            <div class="{{ $tile }}">
                <div class="{{ $tLabel }}">Loss Streak</div>
                <div class="{{ $tValue }}">{{ $s['max_loss_streak'] }}</div>
                <div class="text-xs text-gray-500 dark:text-gray-400">max consecutive</div>
            </div>
        </div>
    </div>

    {{-- ── Curves: two charts, never one dual-axis chart ── --}}
    <div class="mx-6 mt-4 grid grid-cols-1 gap-4 lg:grid-cols-2">
        <section class="{{ $card }}">
            <h3 class="{{ $cardH }}">
                <span>P&amp;L Curve <span class="normal-case font-normal tracking-normal">— cumulative net P&amp;L by day</span></span>
                <x-stats.info-tip label="About P&L Curve">
                    <span class="block">Cumulative net P&amp;L by day, using each trade's local entry time.</span>
                    <span class="block">Reflects whichever accounts/date range are currently selected.</span>
                </x-stats.info-tip>
            </h3>
            <div class="p-4">
                <x-stats.line-chart label="Cumulative net P&L"
                    :points="$stats->pnlCurve()->map(fn ($p) => ['date' => $p['date'], 'value' => $p['cumulative']])" />
            </div>
        </section>

        @php $equity = $this->equity; @endphp
        <section class="{{ $card }}">
            <h3 class="{{ $cardH }}">
                <span>Equity Curve <span class="normal-case font-normal tracking-normal">— balance incl. deposits &amp; withdrawals</span></span>
                <x-stats.info-tip label="About Equity Curve">
                    <span class="block">Account balance including deposits and withdrawals — not just trade P&amp;L.</span>
                    <span class="block">If a start date is set, it opens at the real balance as of that date, not $0.</span>
                    <span class="block">Accounts with no starting balance set are counted as $0 (already flagged separately below the chart).</span>
                </x-stats.info-tip>
            </h3>
            <div class="p-4">
                @if ($equity['points']->isEmpty())
                    <p class="text-sm text-gray-500 dark:text-gray-400">No balance data for the selected accounts.</p>
                @else
                    <x-stats.line-chart label="Account balance" :zero-baseline="false"
                        :points="$equity['points']->map(fn ($p) => ['date' => $p['date'], 'value' => $p['balance']])" />
                @endif
                @if ($equity['missing_balance'] > 0)
                    <p class="mt-2 text-xs text-amber-700 dark:text-amber-400">
                        {{ $equity['missing_balance'] }} selected {{ Str::plural('account', $equity['missing_balance']) }} {{ $equity['missing_balance'] === 1 ? 'has' : 'have' }} no starting balance, counted as $0 —
                        set it on the <a href="{{ route('journal.accounts') }}" wire:navigate class="underline">Accounts</a> page.
                    </p>
                @endif
            </div>
        </section>
    </div>

    {{-- ── Calendar ── --}}
    <section class="mx-6 mt-4 {{ $card }}">
        <h3 class="{{ $cardH }}">
            Daily P&amp;L
            <x-stats.info-tip label="About Daily P&L">
                <span class="block">One cell per trading day, shaded relative to the largest single day's P&amp;L in the current selection, not a fixed scale.</span>
                <span class="block">Hover/click a day for its exact P&amp;L and trade count.</span>
            </x-stats.info-tip>
        </h3>
        <div class="p-4">
            <x-stats.calendar-heatmap :daily="$stats->daily()" />
        </div>
    </section>

    {{-- ── Breakdowns ── --}}
    <div class="mx-6 mt-4 grid grid-cols-1 gap-4 lg:grid-cols-2">
        <x-stats.breakdown-table title="By Trade Type" :tip="$breakdownTip" :rows="$stats->byTradeType()" />
        <x-stats.breakdown-table title="Long vs. Short" :tip="$breakdownTip" :rows="$stats->byDirection()" />
        <x-stats.breakdown-table title="By Day of Week" :tip="$breakdownTip" :rows="$stats->byDayOfWeek()" />
        <x-stats.breakdown-table title="By Time of Day (entry)" :tip="$breakdownTip" :rows="$stats->byHour()" />
        <x-stats.breakdown-table title="By Instrument" :tip="$breakdownTip" :rows="$stats->byInstrument()" />
        <x-stats.breakdown-table title="By Exit Reason" :tip="$breakdownTip" :rows="$stats->byExitReason()" />
    </div>

    {{-- ── Trade quality ── --}}
    @php
        $d = $stats->durations();
        $c = $stats->costs();
        $e = $stats->excursion();
        $r = $stats->runners();
    @endphp
    <div class="mx-6 mt-4 grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
        <section class="{{ $card }}">
            <h3 class="{{ $cardH }}">
                Holding Time
                <x-stats.info-tip label="About Holding Time">
                    <span class="block">Average time between entry and exit.</span>
                    <span class="block">Winners and losers are shown separately on purpose — a big gap between them is usually a sign of cutting winners short or holding losers too long.</span>
                </x-stats.info-tip>
            </h3>
            <dl class="grid grid-cols-2 gap-y-1.5 p-4 text-sm">
                <dt class="{{ $dt }}">All trades</dt><dd class="{{ $dd }}">{{ Format::duration($d['all']) }}</dd>
                <dt class="{{ $dt }}">Winners</dt><dd class="{{ $dd }}">{{ Format::duration($d['winners']) }}</dd>
                <dt class="{{ $dt }}">Losers</dt><dd class="{{ $dd }}">{{ Format::duration($d['losers']) }}</dd>
            </dl>
        </section>

        <section class="{{ $card }}">
            <h3 class="{{ $cardH }}">
                Costs
                <x-stats.info-tip label="About Costs">
                    <span class="block">Total commission and fees, and what share of gross P&amp;L they represent.</span>
                    <span class="block">This is the only panel that shows a real, non-allocated commission figure — trade legs (see Runners below) don't carry their own commission value at all.</span>
                </x-stats.info-tip>
            </h3>
            <dl class="grid grid-cols-2 gap-y-1.5 p-4 text-sm">
                <dt class="{{ $dt }}">Commission</dt><dd class="{{ $dd }}">{{ Format::money($c['commission']) }}</dd>
                <dt class="{{ $dt }}">Fees</dt><dd class="{{ $dd }}">{{ Format::money($c['fees']) }}</dd>
                <dt class="{{ $dt }}">Per trade</dt><dd class="{{ $dd }}">{{ Format::money($c['per_trade']) }}</dd>
                <dt class="{{ $dt }}">% of gross P&amp;L</dt>
                <dd class="{{ $dd }}">{{ $c['pct_of_gross'] === null ? '—' : number_format($c['pct_of_gross'], 1).'%' }}</dd>
            </dl>
            @if ($c['pct_of_gross'] === null)
                <p class="px-4 pb-3 -mt-2 text-xs text-gray-500 dark:text-gray-400">Gross P&amp;L is not positive, so cost share isn't meaningful.</p>
            @endif
        </section>

        <section class="{{ $card }}">
            <h3 class="{{ $cardH }}">
                MAE / MFE
                <x-stats.info-tip label="About MAE / MFE">
                    <span class="block">Averages are from trades with a <em>complete</em> excursion only.</span>
                    <span class="block">A trade's excursion is marked incomplete when the AddOn's price feed dropped mid-trade — those MAE/MFE values are lower bounds, not real numbers, so they're excluded rather than silently understating the average.</span>
                    <span class="block">CSV-imported trades don't have any MAE/MFE at all unless you've also imported a matching NinjaTrader Trades export (Journal Settings).</span>
                    <span class="block">Capture ratio = net P&amp;L ÷ MFE in dollars — how much of the best price move actually available was banked.</span>
                </x-stats.info-tip>
            </h3>
            @if ($e['trades'] === 0)
                <p class="p-4 text-sm text-gray-500 dark:text-gray-400">No trades with a complete excursion in this selection.</p>
            @else
                <dl class="grid grid-cols-2 gap-y-1.5 p-4 text-sm">
                    <dt class="{{ $dt }}">Avg MAE</dt>
                    <dd class="{{ $dd }}">{{ Format::money($e['avg_mae_dollars']) }}@unless ($e['mixed_instruments']) <span class="text-xs text-gray-500 dark:text-gray-400 font-normal">· {{ number_format($e['avg_mae_points'], 2) }} pt</span>@endunless</dd>
                    <dt class="{{ $dt }}">Avg MFE</dt>
                    <dd class="{{ $dd }}">{{ Format::money($e['avg_mfe_dollars']) }}@unless ($e['mixed_instruments']) <span class="text-xs text-gray-500 dark:text-gray-400 font-normal">· {{ number_format($e['avg_mfe_points'], 2) }} pt</span>@endunless</dd>
                    <dt class="{{ $dt }}">Capture ratio</dt>
                    <dd class="{{ $dd }}">{{ $e['capture_ratio'] === null ? '—' : number_format($e['capture_ratio'], 1).'%' }}</dd>
                </dl>
            @endif
            <p class="px-4 pb-3 {{ $e['trades'] === 0 ? '' : '-mt-2' }} text-xs text-gray-500 dark:text-gray-400">
                Based on {{ $e['trades'] }} {{ Str::plural('trade', $e['trades']) }} with a complete excursion.
                @if ($e['incomplete'] > 0)
                    {{ $e['incomplete'] }} with an interrupted price feed {{ $e['incomplete'] === 1 ? 'is' : 'are' }} left out.
                @endif
                Capture = net P&amp;L ÷ MFE in dollars.
            </p>
        </section>

        <section class="{{ $card }}">
            <h3 class="{{ $cardH }}">
                Runners
                <x-stats.info-tip label="About Runners">
                    <span class="block">Runner legs = every exit after a trade's first exit (scaling out and letting the remainder run). Base exits = everything else, including every exit on a single-exit trade.</span>
                    <span class="block">Runner share = runner gross P&amp;L ÷ total gross P&amp;L.</span>
                    <span class="block"><strong>Shown in gross dollars, not net</strong> — the <code class="font-mono">trade_legs</code> table has no commission column at all (only the whole trade does, shown in Costs above), so splitting a trade's commission across its legs would be an invented number, not a real one.</span>
                </x-stats.info-tip>
            </h3>
            @if ($r['trades'] === 0)
                <p class="p-4 text-sm text-gray-500 dark:text-gray-400">No trades with exit-leg data in this selection.</p>
            @else
                <dl class="grid grid-cols-2 gap-y-1.5 p-4 text-sm">
                    <dt class="{{ $dt }}">Runner legs</dt><dd class="{{ $dd }} {{ Format::pnlClass($r['runner_gross']) }}">{{ Format::pnl($r['runner_gross']) }}</dd>
                    <dt class="{{ $dt }}">Base exits</dt><dd class="{{ $dd }} {{ Format::pnlClass($r['base_gross']) }}">{{ Format::pnl($r['base_gross']) }}</dd>
                    <dt class="{{ $dt }}">Runner share</dt>
                    <dd class="{{ $dd }}">{{ $r['runner_share'] === null ? '—' : number_format($r['runner_share'], 1).'%' }}</dd>
                </dl>
                <p class="px-4 pb-3 -mt-2 text-xs text-gray-500 dark:text-gray-400">
                    Gross P&amp;L across {{ $r['trades'] }} {{ Str::plural('trade', $r['trades']) }} with leg data ({{ $r['trades_with_runner'] }} with a runner). Legs carry no commission.
                </p>
            @endif
        </section>
    </div>

    @endif
</div>
