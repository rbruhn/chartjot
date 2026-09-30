<?php

namespace Database\Factories;

use App\Enums\Direction;
use App\Enums\ExitReason;
use App\Enums\TradeType;
use App\Models\Account;
use App\Models\Journal;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class TradeFactory extends Factory
{
    public function definition(): array
    {
        $instrument = $this->faker->randomElement(['ES', 'NQ', 'MES', 'MNQ']);
        $contract = $instrument.' 12-26';
        $tickSize = '0.25';
        $pointValue = match ($instrument) {
            'ES'  => '50.00',
            'NQ'  => '20.00',
            'MES' => '5.00',
            'MNQ' => '2.00',
        };

        $direction = $this->faker->randomElement(Direction::cases());
        $quantity = $this->faker->numberBetween(1, 4);
        $entryPrice = $this->faker->randomFloat(2, 5400, 5800);
        $points = $this->faker->randomFloat(2, -10, 15);
        $exitPrice = $direction === Direction::Long
            ? round($entryPrice + $points, 2)
            : round($entryPrice - $points, 2);
        $grossPnl = round($points * (float) $pointValue * $quantity, 2);
        $commission = round($quantity * 2 * 1.29, 2);
        $netPnl = round($grossPnl - $commission, 2);
        $entryAt = $this->faker->dateTimeBetween('-90 days', '-1 day');
        $exitAt = (clone $entryAt)->modify('+'.rand(1, 30).' minutes');

        return [
            'journal_id'                    => Journal::factory(),
            'account_id'                    => Account::factory(),
            'source_trade_id'               => 'NT8-'.strtoupper(Str::random(8)).'-'.strtolower(Str::random(16)),
            'source'                        => 'ninjatrader_8',
            'addon_version'                 => '1.0.0',
            'trade_type'                    => $this->faker->randomElement(TradeType::cases()),
            'trade_type_other'              => null,
            'instrument'                    => $contract,
            'instrument_symbol'             => $instrument,
            'tick_size'                     => $tickSize,
            'point_value'                   => $pointValue,
            'direction'                     => $direction,
            'quantity'                      => $quantity,
            'total_entry_quantity'          => $quantity,
            'entry_at'                      => $entryAt,
            'exit_at'                       => $exitAt,
            'entry_price'                   => number_format($entryPrice, 2, '.', ''),
            'exit_price'                    => number_format($exitPrice, 2, '.', ''),
            'entry_order_name'              => 'Entry',
            'exit_order_name'               => $this->faker->randomElement(['Stop1', 'Target1', 'Exit']),
            'exit_reason'                   => $this->faker->randomElement(ExitReason::cases()),
            'points'                        => number_format(abs($points), 2, '.', ''),
            'ticks'                         => (int) round(abs($points) / 0.25),
            'gross_pnl'                     => number_format($grossPnl, 2, '.', ''),
            'commission'                    => number_format($commission, 2, '.', ''),
            'fees'                          => null,
            'net_pnl'                       => number_format($netPnl, 2, '.', ''),
            'excursion_mae_points'          => number_format($this->faker->randomFloat(2, 0, 3), 2, '.', ''),
            'excursion_mfe_points'          => number_format($this->faker->randomFloat(2, 0, 5), 2, '.', ''),
            'excursion_max_adverse_price'   => null,
            'excursion_max_favorable_price' => null,
            'excursion_complete'            => true,
            'raw_payload'                   => [],
        ];
    }
}
