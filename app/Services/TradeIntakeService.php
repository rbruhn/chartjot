<?php

namespace App\Services;

use App\Enums\AccountType;
use App\Enums\CopiesSource;
use App\Enums\CopyStatus;
use App\Enums\Direction;
use App\Enums\ExecutionAction;
use App\Enums\ExecutionRole;
use App\Enums\ExitReason;
use App\Enums\NotePhase;
use App\Enums\ScreenshotSource;
use App\Enums\TradeType;
use App\Models\Account;
use App\Models\Journal;
use App\Models\Trade;
use App\Models\TradeCopy;
use App\Models\TradeCopyExecution;
use App\Models\TradeExecution;
use App\Models\TradeLeg;
use App\Models\TradeNote;
use App\Models\TradeScreenshot;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class TradeIntakeService
{
    /**
     * Finds the journal's account by name, creating it when the AddOn reports one the journal has not seen
     * (a new copier follower, for example). The trader can correct its type, balance and timezone on the
     * Accounts page afterwards.
     */
    private function resolveAccount(Journal $journal, string $name, ?string $connection): Account
    {
        $account = Account::firstOrCreate(
            ['journal_id' => $journal->id, 'name' => $name],
            [
                'connection'   => $connection,
                'account_type' => preg_match('/^(sim|playback)/i', $name) ? AccountType::Sim : AccountType::Funded,
            ]
        );

        // Fill in the connection later if the account was first seen as a copy, which carries none.
        if ($account->connection === null && $connection !== null) {
            $account->update(['connection' => $connection]);
        }

        return $account;
    }

    public function store(Journal $journal, array $data, ?UploadedFile $screenshotFile): Trade
    {
        return DB::transaction(function () use ($journal, $data, $screenshotFile) {
            $account = $this->resolveAccount($journal, $data['account_name'], $data['connection'] ?? null);

            $instrument = $data['instrument'];
            $entry      = $data['entry'];
            $exit       = $data['exit'];
            $perf       = $data['performance'];
            $excursion  = $data['excursion'];

            $trade = Trade::create([
                'uuid'                          => (string) Str::ulid(),
                'journal_id'                    => $journal->id,
                'account_id'                    => $account->id,
                'source_trade_id'               => $data['trade_id'],
                'source'                        => $data['source'],
                'addon_version'                 => $data['addon_version'],
                'trade_type'                    => TradeType::from($data['trade_type']),
                'trade_type_other'              => $data['trade_type_other'] ?? null,
                'instrument'                    => $instrument['contract'],
                'instrument_symbol'             => $instrument['symbol'],
                'tick_size'                     => $instrument['tick_size'],
                'point_value'                   => $instrument['point_value'],
                'direction'                     => Direction::from($data['direction']),
                'quantity'                      => $data['quantity'],
                'total_entry_quantity'          => $data['total_entry_quantity'],
                'entry_at'                      => $entry['occurred_at'],
                'exit_at'                       => $exit['occurred_at'],
                'entry_price'                   => $entry['average_price'],
                'exit_price'                    => $exit['average_price'],
                'entry_order_name'              => $entry['order_name'] ?? '',
                'exit_order_name'               => $exit['order_name'] ?? '',
                'exit_reason'                   => ExitReason::from($exit['reason']),
                'points'                        => $perf['points'],
                'ticks'                         => $perf['ticks'],
                'gross_pnl'                     => $perf['gross_pnl'],
                'commission'                    => $perf['commission'],
                'fees'                          => $perf['fees'] ?? null,
                'net_pnl'                       => $perf['net_pnl'],
                'excursion_mae_points'          => $excursion['mae_points'] ?? null,
                'excursion_mfe_points'          => $excursion['mfe_points'] ?? null,
                'excursion_max_adverse_price'   => $excursion['max_adverse_price'] ?? null,
                'excursion_max_favorable_price' => $excursion['max_favorable_price'] ?? null,
                'excursion_complete'            => $excursion['complete'],
                'copies_source'                 => isset($data['copies_source'])
                    ? CopiesSource::from($data['copies_source'])
                    : null,
                'raw_payload'                   => collect($data)->except(['screenshot_file', 'trade'])->all(),
            ]);

            foreach ($data['executions'] ?? [] as $exec) {
                TradeExecution::create([
                    'trade_id'            => $trade->id,
                    'source_execution_id' => $exec['execution_id'],
                    'order_id'            => $exec['order_id'] ?? null,
                    'occurred_at'         => $exec['occurred_at'],
                    'action'              => ExecutionAction::from($exec['action']),
                    'role'                => ExecutionRole::from($exec['role']),
                    'quantity'            => $exec['quantity'],
                    'allocated_quantity'  => $exec['allocated_quantity'],
                    'price'               => $exec['price'],
                    'commission'          => $exec['commission'] ?? null,
                    'fee'                 => $exec['fee'] ?? null,
                    'order_name'          => $exec['order_name'] ?? '',
                    'position_after'      => $exec['position_after'],
                ]);
            }

            foreach ($data['legs'] ?? [] as $leg) {
                TradeLeg::create([
                    'trade_id'           => $trade->id,
                    'sequence'           => $leg['sequence'],
                    'runner'             => $leg['runner'],
                    'exit_order_id'      => $leg['exit_order_id'] ?? null,
                    'order_name'         => $leg['order_name'] ?? '',
                    'reason'             => ExitReason::from($leg['reason']),
                    'quantity'           => $leg['quantity'],
                    'exited_at'          => $leg['exited_at'],
                    'average_exit_price' => $leg['average_exit_price'],
                    'points'             => $leg['points'],
                    'gross_pnl'          => $leg['gross_pnl'],
                    'mae_points'         => $leg['mae_points'] ?? null,
                    'mfe_points'         => $leg['mfe_points'] ?? null,
                ]);
            }

            foreach ($data['copies'] ?? [] as $copyData) {
                $copyAccount = $this->resolveAccount($journal, $copyData['account_name'], null);

                $copyInstrument = $copyData['instrument'] ?? null;
                $copyPerf       = $copyData['performance'] ?? null;
                $copyExpected   = $copyData['expected'] ?? null;

                $copy = TradeCopy::create([
                    'trade_id'                => $trade->id,
                    'account_id'              => $copyAccount->id,
                    'status'                  => CopyStatus::from($copyData['status']),
                    'instrument_contract'     => $copyInstrument['contract'] ?? null,
                    'instrument_symbol'       => $copyInstrument['symbol'] ?? null,
                    'instrument_tick_size'    => $copyInstrument['tick_size'] ?? null,
                    'instrument_point_value'  => $copyInstrument['point_value'] ?? null,
                    'expected_contract_size'  => $copyExpected['contract_size'] ?? null,
                    'expected_multiplier'     => $copyExpected['multiplier'] ?? null,
                    'expected_faded'          => $copyExpected['faded'] ?? null,
                    'expected_blown'          => $copyExpected['blown'] ?? null,
                    'expected_quantity'       => $copyExpected['quantity'] ?? null,
                    'warnings'               => $copyData['warnings'] ?? [],
                    'direction'              => isset($copyData['direction'])
                        ? Direction::from($copyData['direction'])
                        : null,
                    'quantity'               => $copyData['quantity'] ?? 0,
                    'entry_average_price'    => $copyData['entry_average_price'] ?? null,
                    'exit_average_price'     => $copyData['exit_average_price'] ?? null,
                    'entered_at'             => $copyData['entered_at'] ?? null,
                    'exited_at'              => $copyData['exited_at'] ?? null,
                    'points'                 => $copyPerf['points'] ?? null,
                    'ticks'                  => $copyPerf['ticks'] ?? null,
                    'gross_pnl'              => $copyPerf['gross_pnl'] ?? null,
                    'commission'             => $copyPerf['commission'] ?? null,
                    'fees'                   => $copyPerf['fees'] ?? null,
                    'net_pnl'                => $copyPerf['net_pnl'] ?? null,
                ]);

                foreach ($copyData['executions'] ?? [] as $exec) {
                    TradeCopyExecution::create([
                        'trade_copy_id'       => $copy->id,
                        'source_execution_id' => $exec['execution_id'],
                        'order_id'            => $exec['order_id'] ?? null,
                        'occurred_at'         => $exec['occurred_at'],
                        'action'              => ExecutionAction::from($exec['action']),
                        'role'                => ExecutionRole::from($exec['role']),
                        'quantity'            => $exec['quantity'],
                        'allocated_quantity'  => $exec['allocated_quantity'],
                        'price'               => $exec['price'],
                        'commission'          => $exec['commission'] ?? null,
                        'fee'                 => $exec['fee'] ?? null,
                        'order_name'          => $exec['order_name'] ?? '',
                        'position_after'      => $exec['position_after'],
                    ]);
                }
            }

            foreach ($data['notes'] ?? [] as $note) {
                TradeNote::create([
                    'trade_id'    => $trade->id,
                    'created_by'  => $journal->user_id,
                    'body'        => $note['body'],
                    'phase'       => NotePhase::from($note['phase']),
                    'occurred_at' => $note['occurred_at'],
                ]);
            }

            if ($screenshotFile) {
                $ext  = $screenshotFile->extension();
                $path = "trade-screenshots/{$journal->id}/{$trade->uuid}.{$ext}";
                Storage::disk('local')->putFileAs(
                    "trade-screenshots/{$journal->id}",
                    $screenshotFile,
                    "{$trade->uuid}.{$ext}"
                );

                TradeScreenshot::create([
                    'trade_id'    => $trade->id,
                    'disk'        => 'local',
                    'path'        => $path,
                    'caption'     => $data['screenshot']['caption'] ?? null,
                    'mime_type'   => $screenshotFile->getMimeType(),
                    'bytes'       => $screenshotFile->getSize(),
                    'captured_at' => $data['screenshot']['captured_at'] ?? null,
                    'source'      => ScreenshotSource::Nt8,
                ]);
            }

            return $trade;
        });
    }
}
