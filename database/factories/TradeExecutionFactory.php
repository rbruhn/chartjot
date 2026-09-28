<?php

namespace Database\Factories;

use App\Enums\ExecutionAction;
use App\Enums\ExecutionRole;
use App\Models\Trade;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class TradeExecutionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'trade_id'            => Trade::factory(),
            'source_execution_id' => Str::random(12),
            'order_id'            => 'ord-'.Str::random(8),
            'occurred_at'         => $this->faker->dateTimeBetween('-90 days', 'now'),
            'action'              => $this->faker->randomElement(ExecutionAction::cases()),
            'role'                => $this->faker->randomElement(ExecutionRole::cases()),
            'quantity'            => $this->faker->numberBetween(1, 4),
            'allocated_quantity'  => $this->faker->numberBetween(1, 4),
            'price'               => number_format($this->faker->randomFloat(2, 5400, 5800), 2, '.', ''),
            'commission'          => number_format($this->faker->randomFloat(2, 1, 5), 2, '.', ''),
            'fee'                 => null,
            'order_name'          => $this->faker->randomElement(['Entry', 'Target1', 'Stop1']),
            'position_after'      => $this->faker->numberBetween(-4, 4),
        ];
    }
}
