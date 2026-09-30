<?php

namespace App\Services;

use App\Enums\Direction;
use App\Mail\FailedImportsMail;
use App\Models\Account;
use App\Models\FailedTradeImport;
use App\Models\Journal;
use App\Models\Trade;
use App\Models\TradeLeg;
use App\Support\NinjaTraderCsv;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

/**
 * Enriches trades created by an Executions import with leg-level MAE/MFE
 * from NinjaTrader's Trades export (Trade Performance → Trades). It never
 * creates a trade: every row must attach to one that already exists.
 *
 * What the real export looks like (checked against actual NT8 files):
 * - One row per entry-fill/exit-fill pairing (NT8 pairs them FIFO), so a
 *   scale-in contract's row carries ITS entry time, not the trade's first
 *   one. Rows are therefore matched to the trade whose entry→exit span
 *   contains them (same account and instrument), not by exact entry time.
 * - MAE and MFE are dollar amounts for the row's whole quantity (the grid's
 *   default Currency display unit), e.g. "$500.00" on 2 ES contracts is
 *   5 points. They're converted with the trade's point value. Any other
 *   display unit (points, ticks, percent) is rejected — a bare number can't
 *   be told apart.
 * - Profit is net of that row's Commission, so it isn't used: leg gross
 *   P&L comes from prices, like the trade-level figure.
 *
 * Each row becomes a TradeLeg. A trade's legs are replaced as a set on
 * every import, so re-uploading the same file is idempotent.
 */
class TradesMaeMfeImporter
{
    private const REQUIRED_COLUMNS = [
        'Instrument', 'Account', 'Qty', 'Exit price', 'Entry time', 'Exit time', 'Exit name', 'MAE', 'MFE',
    ];

    /**
     * @return array{legs_imported: int, trades_enriched: int, rows_skipped: int, errors: array<int, string>}
     */
    public function import(Journal $journal, string $filePath): array
    {
        if (blank($journal->timezone)) {
            return [
                'legs_imported'   => 0,
                'trades_enriched' => 0,
                'rows_skipped'    => 0,
                'errors'          => ['Journal timezone is not set. Configure it in Journal Settings before importing.'],
            ];
        }

        [$rows, $failures] = $this->parseRows($journal, $filePath);

        $timezones = NinjaTraderCsv::accountTimezones($journal);
        $accounts  = $journal->accounts()->get()->keyBy('name');
        $byTrade   = [];
        $trades    = [];
        $skipped   = 0;

        foreach ($rows as $row) {
            $account = $accounts->get($row['account_name']);
            if (! $account) {
                $failures[] = $this->failure($row, "Account '{$row['account_name']}' not found in this journal, so no imported trade can match this row.");
                continue;
            }

            $timezone = $timezones->get($row['account_name'], $journal->timezone);
            $entryAt  = NinjaTraderCsv::parseTime($row['entry_raw'], $timezone)->utc();
            $exitAt   = NinjaTraderCsv::parseTime($row['exit_raw'], $timezone)->utc();

            $matches = Trade::where('journal_id', $journal->id)
                ->where('account_id', $account->id)
                ->where('instrument', $row['instrument'])
                ->where('entry_at', '<=', $entryAt)
                ->where('exit_at', '>=', $exitAt)
                ->get();

            if ($matches->count() !== 1) {
                $failures[] = $this->failure($row, $matches->isEmpty()
                    ? "No matching trade: no imported trade on account {$row['account_name']} for {$row['instrument']} "
                        ."spans {$row['entry_raw']} → {$row['exit_raw']}. Import the Executions export covering this trade first."
                    : "Ambiguous: {$matches->count()} trades on account {$row['account_name']} for {$row['instrument']} "
                        ."span {$row['entry_raw']} → {$row['exit_raw']}.");
                continue;
            }

            $trade = $matches->first();

            // AddOn-reported trades already have live-tracked legs and excursion.
            if ($trade->source !== 'csv_import') {
                $skipped++;
                continue;
            }

            $row['exited_at'] = $exitAt;
            $trades[$trade->id]  = $trade;
            $byTrade[$trade->id][] = $row;
        }

        $legsImported = 0;
        $enriched     = 0;

        foreach ($byTrade as $tradeId => $tradeRows) {
            $trade   = $trades[$tradeId];
            $covered = array_sum(array_column($tradeRows, 'quantity'));

            // A partial set of rows (e.g. an export cut off by a date filter) would
            // misstate the legs, so the trade is left untouched and reported.
            if ($covered !== (int) $trade->total_entry_quantity) {
                foreach ($tradeRows as $row) {
                    $failures[] = $this->failure($row,
                        "Trade found (account {$row['account_name']}, {$row['instrument']}) but this file's rows cover "
                        ."{$covered} of {$trade->total_entry_quantity} contracts, so its legs can't be built. "
                        .'Re-export the Trades grid covering the whole trade.',
                        $trade->source_trade_id);
                }
                continue;
            }

            DB::transaction(fn () => $this->replaceLegs($trade, $tradeRows));
            $legsImported += count($tradeRows);
            $enriched++;
        }

        $this->recordFailures($journal, $failures);

        return [
            'legs_imported'   => $legsImported,
            'trades_enriched' => $enriched,
            'rows_skipped'    => $skipped,
            'errors'          => array_column($failures, 'reason'),
        ];
    }

    // -------------------------------------------------------------------------

    /**
     * @return array{0: array<int, array>, 1: array<int, array>} valid rows, and failures for malformed ones
     */
    private function parseRows(Journal $journal, string $filePath): array
    {
        $handle = fopen($filePath, 'r');
        $header = fgetcsv($handle, null, ',', '"', '') ?: [];
        if (isset($header[0])) {
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
        }
        $header = array_map('trim', $header);

        $missing = array_diff(self::REQUIRED_COLUMNS, $header);
        if ($missing) {
            fclose($handle);
            throw new RuntimeException(
                'File does not appear to be an NT8 Trade Performance → Trades export (missing '.implode(', ', $missing).').'
            );
        }

        $rows = [];
        $failures = [];
        $line = 1;

        while (($values = fgetcsv($handle, null, ',', '"', '')) !== false) {
            $line++;
            if (count(array_filter($values, fn ($v) => trim((string) $v) !== '')) === 0) {
                continue;
            }

            // Name every value by its column, so the raw row can be stored with a failure.
            $raw = [];
            foreach ($header as $i => $name) {
                if ($name !== '') {
                    $raw[$name] = trim($values[$i] ?? '');
                }
            }

            [$accountName] = NinjaTraderCsv::parseAccountName($raw['Account']);
            $row = [
                'line'         => $line,
                'raw'          => $raw,
                'account_name' => $accountName,
                'instrument'   => $raw['Instrument'],
                'entry_raw'    => $raw['Entry time'],
                'exit_raw'     => $raw['Exit time'],
                'exit_name'    => $raw['Exit name'],
            ];

            if ($reason = $this->invalidRowReason($raw)) {
                $failures[] = $this->failure($row, "Row {$line}: {$reason}");
                continue;
            }

            $row['quantity']   = (int) $raw['Qty'];
            $row['exit_price'] = (float) $raw['Exit price'];
            $row['mae_dollars'] = self::currency($raw['MAE']);
            $row['mfe_dollars'] = self::currency($raw['MFE']);
            $rows[] = $row;
        }

        fclose($handle);

        return [$rows, $failures];
    }

    private function invalidRowReason(array $raw): ?string
    {
        if (! ctype_digit($raw['Qty']) || (int) $raw['Qty'] < 1) {
            return "invalid quantity '{$raw['Qty']}'";
        }
        if (! is_numeric($raw['Exit price']) || (float) $raw['Exit price'] <= 0) {
            return "invalid exit price '{$raw['Exit price']}'";
        }
        if (! NinjaTraderCsv::isValidTime($raw['Entry time'])) {
            return "invalid entry time '{$raw['Entry time']}'";
        }
        if (! NinjaTraderCsv::isValidTime($raw['Exit time'])) {
            return "invalid exit time '{$raw['Exit time']}'";
        }

        $mae = self::currency($raw['MAE']);
        $mfe = self::currency($raw['MFE']);
        if ($mae === null || $mfe === null) {
            return "MAE/MFE must be exported in currency (e.g. \$125.00), got '{$raw['MAE']}' / '{$raw['MFE']}'. "
                ."Set the Trades grid's display unit to Currency and export again.";
        }
        if ($mae < 0 || $mfe < 0) {
            return 'MAE/MFE cannot be negative';
        }

        return null;
    }

    /** "$500.00" → 500.0, "($12.50)" → -12.5; null when the value isn't a currency amount. */
    private static function currency(string $raw): ?float
    {
        $value = trim($raw);
        if (! str_contains($value, '$')) {
            return null;
        }

        $negative = str_starts_with($value, '(') && str_ends_with($value, ')') || str_starts_with($value, '-');
        $number   = str_replace(['$', ',', '(', ')', '-', ' '], '', $value);

        return is_numeric($number) ? ($negative ? -1 : 1) * (float) $number : null;
    }

    /**
     * Rebuild a trade's legs from its rows, then its excursion from the legs.
     *
     * - sequence: by exit time (file order breaks ties).
     * - points / gross_pnl: from the trade's average entry, direction and
     *   point value — the same basis as the trade-level figures and the
     *   AddOn's legs, so a trade's legs add up to its gross P&L.
     * - runner: exited after the trade's first exit. The AddOn marks every
     *   leg after the first exit order as a runner; CSV rows aren't orders
     *   (one exit can span several rows), so rows exiting in the same second
     *   as the first exit count as the base, not runners.
     * - mae/mfe: the row's dollars ÷ (quantity × point value).
     */
    private function replaceLegs(Trade $trade, array $rows): void
    {
        usort($rows, fn (array $a, array $b) => [$a['exited_at']->getTimestamp(), $a['line']] <=> [$b['exited_at']->getTimestamp(), $b['line']]);

        $sign        = $trade->direction === Direction::Long ? 1 : -1;
        $avgEntry    = $this->averageEntry($trade);
        $pointValue  = (float) $trade->point_value;
        $firstExitAt = $rows[0]['exited_at']->getTimestamp();

        $trade->legs()->delete();

        $legs = collect($rows)->values()->map(function (array $row, int $i) use ($trade, $sign, $avgEntry, $pointValue, $firstExitAt) {
            $points = $sign * ($row['exit_price'] - $avgEntry);
            $perContract = $row['quantity'] * $pointValue;

            return TradeLeg::create([
                'trade_id'           => $trade->id,
                'sequence'           => $i + 1,
                'runner'             => $row['exited_at']->getTimestamp() > $firstExitAt,
                'exit_order_id'      => $row['exit_name'],
                'order_name'         => $row['exit_name'],
                'reason'             => NinjaTraderCsv::exitReasonFromName($row['exit_name']),
                'quantity'           => $row['quantity'],
                'exited_at'          => $row['exited_at'],
                'average_exit_price' => $row['exit_price'],
                'points'             => round($points, 4),
                'gross_pnl'          => round($points * $row['quantity'] * $pointValue, 2),
                'mae_points'         => round($row['mae_dollars'] / $perContract, 4),
                'mfe_points'         => round($row['mfe_dollars'] / $perContract, 4),
            ]);
        });

        $trade->update([
            'excursion_mae_points' => $legs->max(fn (TradeLeg $l) => (float) $l->mae_points),
            'excursion_mfe_points' => $legs->max(fn (TradeLeg $l) => (float) $l->mfe_points),
            'excursion_complete'   => true,
        ]);
    }

    /**
     * The trade's exact average entry, from its entry executions. trades.entry_price
     * is rounded to 4 decimals, which on a many-contract trade is enough to make
     * the legs' gross P&L drift a few cents from the trade's.
     */
    private function averageEntry(Trade $trade): float
    {
        $entries = $trade->executions()->where('role', 'entry')->get(['price', 'allocated_quantity']);
        $quantity = $entries->sum('allocated_quantity');

        return $quantity > 0
            ? $entries->sum(fn ($e) => (float) $e->price * $e->allocated_quantity) / $quantity
            : (float) $trade->entry_price;
    }

    // -------------------------------------------------------------------------

    private function failure(array $row, string $reason, ?string $sourceTradeId = null): array
    {
        return [
            'account_name'    => $row['account_name'] !== '' ? $row['account_name'] : 'Unknown',
            'source_trade_id' => $sourceTradeId,
            'reason'          => $reason,
            'payload'         => $row['raw'],
        ];
    }

    /** One FailedTradeImport per failed row, and one email for the whole upload. */
    private function recordFailures(Journal $journal, array $failures): void
    {
        if (! $failures) {
            return;
        }

        $now = now();
        foreach ($failures as $f) {
            FailedTradeImport::create([
                'journal_id'      => $journal->id,
                'account_name'    => $f['account_name'],
                'source_trade_id' => $f['source_trade_id'],
                'reason'          => mb_strimwidth($f['reason'], 0, 255, '…'),
                'payload'         => $f['payload'],
                'occurred_at'     => $now,
            ]);
        }

        Mail::to($journal->user()->value('email'))->send(new FailedImportsMail(
            $journal->name,
            array_map(fn (array $f) => [
                'account_name'    => $f['account_name'],
                'source_trade_id' => $f['source_trade_id'],
                'reason'          => $f['reason'],
                'occurred_at'     => $now->toDateTimeString(),
            ], $failures),
            FailedImportsMail::KIND_MAE_MFE,
        ));
    }
}
