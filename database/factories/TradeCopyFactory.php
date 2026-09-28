<?php

namespace Database\Factories;

use App\Enums\CopyStatus;
use App\Enums\Direction;
use App\Models\Account;
use App\Models\Trade;
use Illuminate\Database\Eloquent\Factories\Factory;

class TradeCopyFactory extends Factory
{
    public function definition(): array
    {
        return [
            'trade_id'                => Trade::factory(),
            'account_id'              => Account::factory(),
            'status'                  => CopyStatus::Matched,
            'instrument_contract'     => 'MES 12-26',
            'instrument_symbol'       => 'MES',
            'instrument_tick_size'    => '0.25',
            'instrument_point_value'  => '5.00',
            'expected_contract_size'  => 'micro',
            'expected_multiplier'     => '1.0000',
            'expected_faded'          => false,
            'expected_blown'          => false,
            'expected_quantity'       => $this->faker->numberBetween(1, 4),
            'warnings'                => [],
            'direction'               => $this->faker->randomElement(Direction::cases()),
            'quantity'                => $this->faker->numberBetween(1, 4),
            'entry_average_price'     => number_format($this->faker->randomFloat(2, 5400, 5800), 2, '.', ''),
            'exit_average_price'      => number_format($this->faker->randomFloat(2, 5400, 5800), 2, '.', ''),
            'entered_at'              => $this->faker->dateTimeBetween('-90 days', '-1 hour'),
            'exited_at'               => $this->faker->dateTimeBetween('-1 hour', 'now'),
            'points'                  => number_format($this->faker->randomFloat(2, -10, 15), 2, '.', ''),
            'ticks'                   => $this->faker->numberBetween(-40, 60),
            'gross_pnl'               => number_format($this->faker->randomFloat(2, -100, 200), 2, '.', ''),
            'commission'              => number_format($this->faker->randomFloat(2, 0.5, 3), 2, '.', ''),
            'fees'                    => null,
            'net_pnl'                 => number_format($this->faker->randomFloat(2, -110, 198), 2, '.', ''),
        ];
    }

    public function missed(): static
    {
        return $this->state([
            'status'                 => CopyStatus::Missed,
            'instrument_contract'    => null,
            'instrument_symbol'      => null,
            'instrument_tick_size'   => null,
            'instrument_point_value' => null,
            'direction'              => null,
            'quantity'               => 0,
            'entry_average_price'    => null,
            'exit_average_price'     => null,
            'entered_at'             => null,
            'exited_at'              => null,
            'points'                 => null,
            'ticks'                  => null,
            'gross_pnl'              => null,
            'commission'             => null,
            'fees'                   => null,
            'net_pnl'                => null,
        ]);
    }
}
