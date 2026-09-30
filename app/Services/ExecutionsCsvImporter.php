<?php

namespace App\Services;

use App\Enums\Direction;
use App\Enums\ExecutionAction;
use App\Enums\ExecutionRole;
use App\Enums\TradeType;
use App\Mail\FailedImportsMail;
use App\Models\Account;
use App\Models\FailedTradeImport;
use App\Models\Journal;
use App\Models\Trade;
use App\Models\TradeExecution;
use App\Support\NinjaTraderCsv;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class ExecutionsCsvImporter
{
    private static array $instrumentSpecs = [
        'ES' => ['tick_size' => '0.25',  'point_value' => '50.00'],
        'MES' => ['tick_size' => '0.25',  'point_value' => '5.00'],
        'NQ' => ['tick_size' => '0.25',  'point_value' => '20.00'],
        'MNQ' => ['tick_size' => '0.25',  'point_value' => '2.00'],
        'YM' => ['tick_size' => '1.00',  'point_value' => '5.00'],
        'MYM' => ['tick_size' => '1.00',  'point_value' => '0.50'],
        'RTY' => ['tick_size' => '0.10',  'point_value' => '50.00'],
        'M2K' => ['tick_size' => '0.10',  'point_value' => '5.00'],
        'GC' => ['tick_size' => '0.10',  'point_value' => '10.00'],
        'SI' => ['tick_size' => '0.005', 'point_value' => '5000.00'],
        'CL' => ['tick_size' => '0.01',  'point_value' => '1000.00'],
        'NG' => ['tick_size' => '0.001', 'point_value' => '10000.00'],
    ];

    public function import(Journal $journal, string $filePath): array
    {
        if (blank($journal->timezone)) {
            return [
                'trades_created' => 0,
                'trades_skipped' => 0,
                'errors' => ['Journal timezone is not set. Configure it in Journal Settings before importing.'],
            ];
        }

        [$rawFills, $malformedRows] = $this->parseFills($filePath);

        $created  = 0;
        $skipped  = 0;
        $errors   = [];
        $failures = [];

        // A malformed row can't be imported, and dropping just that fill would
        // throw off the running position for its account/instrument, so every
        // fill in that series is withheld. Re-importing after fixing the file
        // is safe: trades already imported are skipped as duplicates.
        $withheldSeries = [];
        foreach ($malformedRows as $bad) {
            $withheldSeries[$bad['account_name'].'|'.$bad['instrument']] = true;

            $reason = "Row {$bad['line']}: {$bad['reason']}. Other fills for "
                .Str::limit($bad['instrument'], 32).' on this account were not imported; fix the row and re-import.';

            FailedTradeImport::create([
                'journal_id'      => $journal->id,
                'account_name'    => $bad['account_name'],
                'source_trade_id' => null,
                'reason'          => $reason,
                'occurred_at'     => now(),
            ]);

            $failures[] = [
                'account_name'    => $bad['account_name'],
                'source_trade_id' => null,
                'reason'          => $reason,
                'occurred_at'     => now()->toDateTimeString(),
            ];

            $errors[] = $reason;
        }

        $rawFills = array_values(array_filter(
            $rawFills,
            fn (array $f) => ! isset($withheldSeries[$f['account_name'].'|'.$f['instrument']])
        ));

        $fills = $this->resolveFillTimes($journal, $rawFills);
        $tradeGroups = $this->groupIntoTrades($fills);

        foreach ($tradeGroups as $group) {
            try {
                $sourceTradeId = $this->sourceTradeId($group);

                if (Trade::where('journal_id', $journal->id)
                    ->where('source_trade_id', $sourceTradeId)
                    ->exists()
                ) {
                    $skipped++;
                    continue;
                }

                DB::transaction(fn () => $this->insertTrade($journal, $group));
                $created++;
            } catch (\App\Exceptions\UnknownAccountException $e) {
                $accountName = $e->accountName;
                $tradeId     = $this->sourceTradeId($group);

                FailedTradeImport::create([
                    'journal_id'      => $journal->id,
                    'account_name'    => $accountName,
                    'source_trade_id' => $tradeId,
                    'reason'          => $e->getMessage(),
                    'occurred_at'     => now(),
                ]);

                $failures[] = [
                    'account_name'    => $accountName,
                    'source_trade_id' => $tradeId,
                    'reason'          => $e->getMessage(),
                    'occurred_at'     => now()->toDateTimeString(),
                ];

                $errors[] = $e->getMessage();
            } catch (\Throwable $e) {
                $errors[] = $e->getMessage();
            }
        }

        if ($failures) {
            Mail::to($journal->user()->value('email'))
                ->send(new FailedImportsMail($journal->name, $failures));
        }

        return [
            'trades_created' => $created,
            'trades_skipped' => $skipped,
            'errors'         => $errors,
        ];
    }

    // -------------------------------------------------------------------------

    /**
     * @return array{0: array, 1: array<array{line:int,account_name:string,instrument:string,reason:string}>}
     */
    private function parseFills(string $filePath): array
    {
        $fills = [];
        $malformed = [];
        $line = 1;
        $handle = fopen($filePath, 'r');

        // Strip UTF-8 BOM from the first line if present
        $header = fgets($handle);
        if (str_starts_with($header, "\xEF\xBB\xBF")) {
            $header = substr($header, 3);
        }

        // Validate this looks like an NT8 Executions export
        if (! str_contains($header, 'Instrument') || ! str_contains($header, 'E/X')) {
            fclose($handle);
            throw new \RuntimeException('File does not appear to be an NT8 Trade Performance → Executions export.');
        }

        while (($row = fgetcsv($handle)) !== false) {
            $line++;

            if (count($row) < 13 || empty(trim($row[0]))) {
                continue;
            }

            [$accountName, $connection] = NinjaTraderCsv::parseAccountName(
                trim($row[12]),
                trim($row[13] ?? '')
            );

            if ($reason = $this->invalidRowReason($row)) {
                $malformed[] = [
                    'line'         => $line,
                    'account_name' => $accountName,
                    'instrument'   => trim($row[0]),
                    'reason'       => $reason,
                ];

                continue;
            }

            $fills[] = [
                'instrument' => trim($row[0]),
                'instrument_symbol' => NinjaTraderCsv::extractSymbol(trim($row[0])),
                'action' => strtolower(trim($row[1])) === 'buy' ? 'buy' : 'sell',
                'quantity' => (int) trim($row[2]),
                'price' => trim($row[3]),
                'occurred_at_raw' => trim($row[4]),
                'source_execution_id' => trim($row[5]),
                'role' => strtolower(trim($row[6])) === 'entry' ? 'entry' : 'exit',
                'position_after' => $this->parsePosition(trim($row[7])),
                'order_id' => trim($row[8]),
                'order_name' => trim($row[9]),
                'commission' => $this->parseCommission(trim($row[10])),
                'account_name' => $accountName,
                'connection' => $connection,
                'allocated_quantity' => (int) trim($row[2]),
            ];
        }

        fclose($handle);

        return [$fills, $malformed];
    }

    /**
     * Why a fill row can't be trusted, or null if its quantity, price and time
     * all parse. Casting these blindly would turn garbage into 0s (or throw
     * mid-import on a bad timestamp).
     */
    private function invalidRowReason(array $row): ?string
    {
        $quantity = trim($row[2]);
        if (! ctype_digit($quantity) || (int) $quantity < 1) {
            return "invalid quantity '".Str::limit($quantity, 32)."'";
        }

        $price = trim($row[3]);
        if (! is_numeric($price) || (float) $price <= 0) {
            return "invalid price '".Str::limit($price, 32)."'";
        }

        $time = trim($row[4]);
        if (! NinjaTraderCsv::isValidTime($time)) {
            return "invalid time '".Str::limit($time, 32)."'";
        }

        return null;
    }

    /**
     * Convert each fill's raw timestamp using its own account's effective
     * timezone (account override, falling back to the journal default) —
     * not a single blanket timezone for the whole file. A CSV can cover
     * multiple accounts, and accounts can each have their own timezone.
     */
    private function resolveFillTimes(Journal $journal, array $rawFills): array
    {
        $accountTimezones = NinjaTraderCsv::accountTimezones($journal);

        foreach ($rawFills as &$fill) {
            $timezone = $accountTimezones->get($fill['account_name'], $journal->timezone);
            $fill['occurred_at'] = NinjaTraderCsv::parseTime($fill['occurred_at_raw'], $timezone);
            unset($fill['occurred_at_raw']);
        }

        return $rawFills;
    }

    private function groupIntoTrades(array $fills): array
    {
        // Sort by account, instrument, then time so fills arrive in order
        usort($fills, function (array $a, array $b): int {
            $keyA = $a['account_name'].'|'.$a['instrument'].'|'.$a['occurred_at']->timestamp.'|'.$a['source_execution_id'];
            $keyB = $b['account_name'].'|'.$b['instrument'].'|'.$b['occurred_at']->timestamp.'|'.$b['source_execution_id'];

            return strcmp($keyA, $keyB);
        });

        $trades = [];
        $buckets = [];  // current open fills per "account|instrument"
        $positions = [];  // running position per "account|instrument"

        foreach ($fills as $fill) {
            $key = $fill['account_name'].'|'.$fill['instrument'];

            $positions[$key] ??= 0;
            $buckets[$key] ??= [];

            $delta = $fill['action'] === 'buy' ? $fill['quantity'] : -$fill['quantity'];
            $current = $positions[$key];
            $newPosition = $current + $delta;

            $isReversal = $current !== 0
                && $newPosition !== 0
                && ($current > 0) !== ($newPosition > 0);

            if ($isReversal) {
                $closeQty = abs($current);
                $openQty  = abs($newPosition);

                $closeFill                       = $fill;
                $closeFill['allocated_quantity'] = $closeQty;
                $closeFill['role']               = 'exit';
                $buckets[$key][]                 = $closeFill;
                $trades[]                        = $buckets[$key];

                $openFill                       = $fill;
                $openFill['allocated_quantity'] = $openQty;
                $openFill['role']               = 'entry';
                $buckets[$key]                  = [$openFill];
            } elseif ($newPosition === 0) {
                $buckets[$key][] = $fill;
                $trades[] = $buckets[$key];
                $buckets[$key] = [];
            } else {
                $buckets[$key][] = $fill;
            }

            $positions[$key] = $newPosition;
        }

        return $trades;
    }

    private function insertTrade(Journal $journal, array $fills): void
    {
        $first = $fills[0];
        $symbol = $first['instrument_symbol'];
        $specs = self::$instrumentSpecs[$symbol] ?? ['tick_size' => '0.25', 'point_value' => '50.00'];

        $account = Account::where('journal_id', $journal->id)
            ->where('name', $first['account_name'])
            ->first();

        if (! $account) {
            throw new \App\Exceptions\UnknownAccountException($first['account_name']);
        }

        $entryFills = array_values(array_filter($fills, fn ($f) => $f['role'] === 'entry'));
        $exitFills = array_values(array_filter($fills, fn ($f) => $f['role'] === 'exit'));

        if (empty($entryFills) || empty($exitFills)) {
            throw new \RuntimeException(
                "Trade group for {$first['instrument']} on account {$first['account_name']} "
                .'has no '.(empty($entryFills) ? 'entry' : 'exit').' fills — skipping.'
            );
        }

        $avgEntryPrice = $this->weightedAvgPrice($entryFills);
        $avgExitPrice = $this->weightedAvgPrice($exitFills);
        $totalEntryQty = array_sum(array_column($entryFills, 'allocated_quantity'));
        $totalCommission = round(array_sum(array_column($fills, 'commission')), 2);

        $direction = $entryFills[0]['action'] === 'buy' ? Direction::Long : Direction::Short;
        $tickSize = (float) $specs['tick_size'];
        $pointValue = (float) $specs['point_value'];

        $points = $direction === Direction::Long
            ? $avgExitPrice - $avgEntryPrice
            : $avgEntryPrice - $avgExitPrice;

        $ticks = (int) round($points / $tickSize);
        $grossPnl = round($points * $totalEntryQty * $pointValue, 2);
        $netPnl = round($grossPnl - $totalCommission, 2);

        $lastExit = end($exitFills);
        $exitReason = NinjaTraderCsv::exitReasonFromName($lastExit['order_name']);
        $maxPosition = $this->maxPosition($fills);

        $trade = Trade::create([
            'uuid' => (string) Str::ulid(),
            'journal_id' => $journal->id,
            'account_id' => $account->id,
            'source_trade_id' => $this->sourceTradeId($fills),
            'source' => 'csv_import',
            'trade_type' => TradeType::Other,
            'instrument' => $first['instrument'],
            'instrument_symbol' => $symbol,
            'tick_size' => $specs['tick_size'],
            'point_value' => $specs['point_value'],
            'direction' => $direction,
            'quantity' => $maxPosition,
            'total_entry_quantity' => $totalEntryQty,
            'entry_at' => $entryFills[0]['occurred_at'],
            'exit_at' => $lastExit['occurred_at'],
            'entry_price' => number_format($avgEntryPrice, 4, '.', ''),
            'exit_price' => number_format($avgExitPrice, 4, '.', ''),
            'entry_order_name' => $entryFills[0]['order_name'],
            'exit_order_name' => $lastExit['order_name'],
            'exit_reason' => $exitReason,
            'points' => number_format($points, 4, '.', ''),
            'ticks' => $ticks,
            'gross_pnl' => $grossPnl,
            'commission' => $totalCommission,
            'net_pnl' => $netPnl,
            'excursion_complete' => false,
        ]);

        foreach ($fills as $fill) {
            TradeExecution::create([
                'trade_id' => $trade->id,
                'source_execution_id' => $fill['source_execution_id'],
                'order_id' => $fill['order_id'],
                'occurred_at' => $fill['occurred_at'],
                'action' => ExecutionAction::from($fill['action']),
                'role' => ExecutionRole::from($fill['role']),
                'quantity' => $fill['quantity'],
                'allocated_quantity' => $fill['allocated_quantity'],
                'price' => $fill['price'],
                'commission' => $fill['commission'],
                'order_name' => $fill['order_name'],
                'position_after' => $fill['position_after'],
            ]);
        }
    }

    // -------------------------------------------------------------------------

    private function parsePosition(string $raw): int
    {
        if ($raw === '-' || $raw === '') {
            return 0;
        }

        if (preg_match('/^(\d+)\s+([LS])$/', $raw, $m)) {
            return $m[2] === 'L' ? (int) $m[1] : -(int) $m[1];
        }

        return 0;
    }

    private function parseCommission(string $raw): float
    {
        return (float) str_replace(['$', ','], '', $raw);
    }

    private function weightedAvgPrice(array $fills): float
    {
        $totalQty = array_sum(array_column($fills, 'allocated_quantity'));
        if ($totalQty === 0) {
            return 0.0;
        }

        return array_sum(array_map(
            fn ($f) => (float) $f['price'] * $f['allocated_quantity'],
            $fills
        )) / $totalQty;
    }

    private function maxPosition(array $fills): int
    {
        $max = 0;
        $current = 0;

        foreach ($fills as $fill) {
            $delta = $fill['action'] === 'buy' ? $fill['allocated_quantity'] : -$fill['allocated_quantity'];
            $current += $delta;
            $max = max($max, abs($current));
        }

        return $max;
    }

    private function sourceTradeId(array $fills): string
    {
        $ids = array_column($fills, 'source_execution_id');
        sort($ids);

        return 'csv_'.substr(md5(implode('|', $ids)), 0, 16);
    }
}
