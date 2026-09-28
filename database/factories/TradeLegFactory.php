<?php

namespace Database\Factories;

use App\Enums\ExitReason;
use App\Models\Trade;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class TradeLegFactory extends Factory
{
    public function definition(): array
    {
        return [
            'trade_id'           => Trade::factory(),
            'sequence'           => 1,
            'runner'             => false,
            'exit_order_id'      => 'ord-'.Str::random(8),
            'order_name'         => $this->faker->randomElement(['Target1', 'Stop1', 'Exit']),
            'reason'             => $this->faker->randomElement(ExitReason::cases()),
            'quantity'           => $this->faker->numberBetween(1, 3),
            'exited_at'          => $this->faker->dateTimeBetween('-90 days', 'now'),
            'average_exit_price' => number_format($this->faker->randomFloat(2, 5400, 5800), 2, '.', ''),
            'points'             => number_format($this->faker->randomFloat(2, 0, 10), 2, '.', ''),
            'gross_pnl'          => number_format($this->faker->randomFloat(2, -500, 1000), 2, '.', ''),
            'mae_points'         => number_format($this->faker->randomFloat(2, 0, 3), 2, '.', ''),
            'mfe_points'         => number_format($this->faker->randomFloat(2, 0, 5), 2, '.', ''),
        ];
    }

    public function runner(): static
    {
        return $this->state(['runner' => true]);
    }
}
