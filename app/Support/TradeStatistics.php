<?php

namespace App\Support;

use App\Models\Trade;
use Illuminate\Support\Collection;

/**
 * Aggregate performance statistics over an already-filtered set of trades.
 *
 * Plain Collection math, no SQL aggregation: the statistics page loads the
 * matching trades once (with account.journal and legs eager loaded) and every
 * figure here is derived from that one Collection.
 *
 * Win/loss follows the journal page's summary(): a winner is net_pnl > 0, and
 * everything else — breakeven included — is a loser. Anything bucketed by day
 * or time uses the trade's local entry time (entry_at_local), because raw
 * entry_at is UTC.
 */
class TradeStatistics
{
    /** @var Collection<int, Trade> */
    private Collection $trades;

    public function __construct(Collection $trades)
    {
        $this->trades = $trades->sortBy(fn (Trade $t) => $t->entry_at->getTimestamp())->values();
    }

    public static function isWinner(Trade $trade): bool
    {
        return (float) $trade->net_pnl > 0;
    }

    public function isEmpty(): bool
    {
        return $this->trades->isEmpty();
    }

    // -------------------------------------------------------------------------
    // Headline numbers
    // -------------------------------------------------------------------------

    public function summary(): array
    {
        $total   = $this->trades->count();
        $winners = $this->trades->filter(fn (Trade $t) => self::isWinner($t));
        $losers  = $this->trades->reject(fn (Trade $t) => self::isWinner($t));

        $net        = $this->sumNet($this->trades);
        $grossWins  = $this->sumNet($winners);
        $grossLoss  = abs($this->sumNet($losers));

        [$winStreak, $lossStreak] = $this->streaks();

        return [
            'total'           => $total,
            'wins'            => $winners->count(),
            'losses'          => $losers->count(),
            'win_rate'        => $total > 0 ? round($winners->count() / $total * 100, 2) : 0.0,
            'net_pnl'         => $net,
            'expectancy'      => $total > 0 ? round($net / $total, 2) : 0.0,
            'profit_factor'   => $grossLoss > 0 ? round($grossWins / $grossLoss, 2) : null,
            'avg_win'         => $winners->isNotEmpty() ? $grossWins / $winners->count() : null,
            'avg_loss'        => $losers->isNotEmpty() ? -$grossLoss / $losers->count() : null,
            'largest_win'     => $winners->isNotEmpty() ? (float) $winners->max(fn (Trade $t) => (float) $t->net_pnl) : null,
            'largest_loss'    => $losers->isNotEmpty() ? (float) $losers->min(fn (Trade $t) => (float) $t->net_pnl) : null,
            'max_win_streak'  => $winStreak,
            'max_loss_streak' => $lossStreak,
            'max_drawdown'    => $this->maxDrawdown(),
        ];
    }

    /** @return array{0:int, 1:int} longest run of winners, longest run of losers */
    private function streaks(): array
    {
        $maxWin = $maxLoss = $win = $loss = 0;

        foreach ($this->trades as $trade) {
            if (self::isWinner($trade)) {
                $win++;
                $loss = 0;
            } else {
                $loss++;
                $win = 0;
            }
            $maxWin  = max($maxWin, $win);
            $maxLoss = max($maxLoss, $loss);
        }

        return [$maxWin, $maxLoss];
    }

    /**
     * Largest peak-to-trough drop in cumulative trade P&L, trade by trade,
     * starting from 0. Deliberately P&L-only: on the balance-based equity
     * curve a withdrawal or payout would read as a drawdown, which it isn't.
     */
    private function maxDrawdown(): float
    {
        $cumulative = $peak = $maxDrawdown = 0.0;

        foreach ($this->trades as $trade) {
            $cumulative += (float) $trade->net_pnl;
            $peak        = max($peak, $cumulative);
            $maxDrawdown = max($maxDrawdown, $peak - $cumulative);
        }

        return round($maxDrawdown, 2);
    }

    // -------------------------------------------------------------------------
    // Time series
    // -------------------------------------------------------------------------

    /**
     * Net P&L and trade count per local trading day, oldest first.
     *
     * @return Collection<string, array{pnl: float, count: int}>
     */
    public function daily(): Collection
    {
        return $this->trades
            ->groupBy(fn (Trade $t) => $t->entry_at_local->format('Y-m-d'))
            ->map(fn (Collection $day) => ['pnl' => $this->sumNet($day), 'count' => $day->count()])
            ->sortKeys();
    }

    /**
     * Cumulative net P&L at the end of each local trading day.
     *
     * @return Collection<int, array{date: string, pnl: float, cumulative: float}>
     */
    public function pnlCurve(): Collection
    {
        $running = 0.0;

        return $this->daily()->map(function (array $day, string $date) use (&$running) {
            $running = round($running + $day['pnl'], 2);

            return ['date' => $date, 'pnl' => $day['pnl'], 'cumulative' => $running];
        })->values();
    }

    /**
     * Account balance at the end of each day: opening balance plus cash flows
     * (deposits positive, withdrawals negative) plus that day's trade P&L.
     * Days with only a cash flow still get a point.
     *
     * @param  Collection<int, array{date: string, amount: float}>  $cashFlows
     * @param  Collection<string, array{pnl: float}>  $dailyPnl  keyed by Y-m-d, as from daily()
     * @return Collection<int, array{date: string, balance: float, cash_flow: float, pnl: float}>
     */
    public static function equityCurve(float $openingBalance, Collection $cashFlows, Collection $dailyPnl): Collection
    {
        $flowsByDate = $cashFlows
            ->groupBy('date')
            ->map(fn (Collection $flows) => (float) $flows->sum('amount'));

        $dates = $flowsByDate->keys()->merge($dailyPnl->keys())->unique()->sort()->values();

        $balance = $openingBalance;

        return $dates->map(function (string $date) use (&$balance, $flowsByDate, $dailyPnl) {
            $flow     = $flowsByDate->get($date, 0.0);
            $pnl      = (float) ($dailyPnl->get($date)['pnl'] ?? 0.0);
            $balance  = round($balance + $flow + $pnl, 2);

            return ['date' => $date, 'balance' => $balance, 'cash_flow' => $flow, 'pnl' => $pnl];
        });
    }

    // -------------------------------------------------------------------------
    // Breakdowns
    // -------------------------------------------------------------------------

    public function byTradeType(): Collection
    {
        return $this->breakdown(fn (Trade $t) => $t->trade_type?->label() ?? 'Unspecified')
            ->sortByDesc('count')->values();
    }

    /** Monday → Sunday, local entry day. Days with no trades are omitted. */
    public function byDayOfWeek(): Collection
    {
        return $this->breakdown(
            fn (Trade $t) => $t->entry_at_local->format('l'),
            fn (Trade $t) => $t->entry_at_local->isoWeekday(),
        );
    }

    /** By local entry hour, earliest first. Hours with no trades are omitted. */
    public function byHour(): Collection
    {
        return $this->breakdown(
            fn (Trade $t) => $t->entry_at_local->format('H').':00',
            fn (Trade $t) => (int) $t->entry_at_local->format('G'),
        );
    }

    /** By root symbol (ES, not ES 12-26) so contract rolls aggregate together. */
    public function byInstrument(): Collection
    {
        return $this->breakdown(fn (Trade $t) => $t->instrument_symbol)
            ->sortByDesc('net_pnl')->values();
    }

    public function byExitReason(): Collection
    {
        return $this->breakdown(fn (Trade $t) => $t->exit_reason->label())
            ->sortByDesc('net_pnl')->values();
    }

    public function byDirection(): Collection
    {
        return $this->breakdown(fn (Trade $t) => ucfirst($t->direction->value));
    }

    /**
     * @param  callable(Trade): string  $label
     * @param  (callable(Trade): int)|null  $order  sort key; defaults to the label
     * @return Collection<int, array{label: string, count: int, wins: int, win_rate: float, net_pnl: float, avg_pnl: float}>
     */
    private function breakdown(callable $label, ?callable $order = null): Collection
    {
        $order ??= $label;

        return $this->trades
            ->groupBy(fn (Trade $t) => $order($t))
            ->sortKeys()
            ->map(function (Collection $group) use ($label) {
                $count = $group->count();
                $wins  = $group->filter(fn (Trade $t) => self::isWinner($t))->count();
                $net   = $this->sumNet($group);

                return [
                    'label'    => $label($group->first()),
                    'count'    => $count,
                    'wins'     => $wins,
                    'win_rate' => round($wins / $count * 100, 2),
                    'net_pnl'  => $net,
                    'avg_pnl'  => round($net / $count, 2),
                ];
            })
            ->values();
    }

    // -------------------------------------------------------------------------
    // Trade quality
    // -------------------------------------------------------------------------

    /** Average holding time in seconds; null when the group is empty. */
    public function durations(): array
    {
        $avg = fn (Collection $trades) => $trades->isEmpty() ? null : (float) $trades->avg(
            fn (Trade $t) => $t->entry_at_local->diffInSeconds($t->exit_at_local)
        );

        return [
            'all'     => $avg($this->trades),
            'winners' => $avg($this->trades->filter(fn (Trade $t) => self::isWinner($t))),
            'losers'  => $avg($this->trades->reject(fn (Trade $t) => self::isWinner($t))),
        ];
    }

    /** Commission + fees, and that cost as a share of gross P&L (null unless gross is positive). */
    public function costs(): array
    {
        $commission = round((float) $this->trades->sum(fn (Trade $t) => (float) $t->commission), 2);
        $fees       = round((float) $this->trades->sum(fn (Trade $t) => (float) $t->fees), 2);
        $total      = round($commission + $fees, 2);
        $gross      = round((float) $this->trades->sum(fn (Trade $t) => (float) $t->gross_pnl), 2);

        return [
            'commission'   => $commission,
            'fees'         => $fees,
            'total'        => $total,
            'gross_pnl'    => $gross,
            'per_trade'    => $this->trades->isNotEmpty() ? round($total / $this->trades->count(), 2) : 0.0,
            'pct_of_gross' => $gross > 0 ? round($total / $gross * 100, 2) : null,
        ];
    }

    /**
     * MAE/MFE averages and capture ratio, over trades whose excursion is
     * complete only. excursion_complete = false with values present means the
     * AddOn's price feed dropped while the trade was open, so the recorded
     * high/low may have missed the real extremes: those values are lower
     * bounds and would understate MAE/MFE and overstate capture. They are
     * counted in 'incomplete' so the page can say how many were left out.
     *
     * Dollar figures are points × point value × total entry quantity, so
     * trades on different instruments can be averaged together.
     */
    public function excursion(): array
    {
        $hasValues = fn (Trade $t) => $t->excursion_mae_points !== null && $t->excursion_mfe_points !== null;

        $complete   = $this->trades->filter(fn (Trade $t) => $t->excursion_complete && $hasValues($t));
        $incomplete = $this->trades->filter(fn (Trade $t) => ! $t->excursion_complete && $hasValues($t))->count();

        if ($complete->isEmpty()) {
            return [
                'trades' => 0, 'incomplete' => $incomplete,
                'avg_mae_points' => null, 'avg_mfe_points' => null,
                'avg_mae_dollars' => null, 'avg_mfe_dollars' => null,
                'capture_ratio' => null, 'mixed_instruments' => false,
            ];
        }

        $dollars = fn (Trade $t, string $points) => (float) $t->{$points} * (float) $t->point_value * $t->total_entry_quantity;

        $mfeDollars = (float) $complete->sum(fn (Trade $t) => $dollars($t, 'excursion_mfe_points'));

        return [
            'trades'            => $complete->count(),
            'incomplete'        => $incomplete,
            'avg_mae_points'    => round((float) $complete->avg(fn (Trade $t) => (float) $t->excursion_mae_points), 4),
            'avg_mfe_points'    => round((float) $complete->avg(fn (Trade $t) => (float) $t->excursion_mfe_points), 4),
            'avg_mae_dollars'   => round((float) $complete->avg(fn (Trade $t) => $dollars($t, 'excursion_mae_points')), 2),
            'avg_mfe_dollars'   => round($mfeDollars / $complete->count(), 2),
            // Ratio of sums: net P&L actually banked vs. the favorable move that
            // was available. Ratio of sums, not mean of per-trade ratios, so a
            // trade with a tiny MFE can't blow the figure up.
            'capture_ratio'     => $mfeDollars > 0 ? round($this->sumNet($complete) / $mfeDollars * 100, 2) : null,
            'mixed_instruments' => $complete->pluck('instrument_symbol')->unique()->count() > 1,
        ];
    }

    /**
     * Runner vs. base-exit contribution, from trade_legs gross P&L. Gross, not
     * net: legs carry no commission, and splitting a trade's commission across
     * legs would be an invented allocation. Only trades that have legs count.
     */
    public function runners(): array
    {
        $withLegs = $this->trades->filter(fn (Trade $t) => $t->legs->isNotEmpty());
        $legs     = $withLegs->flatMap(fn (Trade $t) => $t->legs);

        $runner = round((float) $legs->where('runner', true)->sum(fn ($l) => (float) $l->gross_pnl), 2);
        $base   = round((float) $legs->where('runner', false)->sum(fn ($l) => (float) $l->gross_pnl), 2);
        $total  = round($runner + $base, 2);

        return [
            'trades'             => $withLegs->count(),
            'trades_with_runner' => $withLegs->filter(fn (Trade $t) => $t->legs->contains('runner', true))->count(),
            'runner_gross'       => $runner,
            'base_gross'         => $base,
            'total_gross'        => $total,
            'runner_share'       => $total > 0 ? round($runner / $total * 100, 2) : null,
        ];
    }

    // -------------------------------------------------------------------------

    private function sumNet(Collection $trades): float
    {
        return round((float) $trades->sum(fn (Trade $t) => (float) $t->net_pnl), 2);
    }
}
