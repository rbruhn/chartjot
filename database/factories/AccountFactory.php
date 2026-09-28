<?php

namespace Database\Factories;

use App\Enums\AccountType;
use App\Models\Journal;
use Illuminate\Database\Eloquent\Factories\Factory;

class AccountFactory extends Factory
{
    public function definition(): array
    {
        $prefix = $this->faker->randomElement(['APEX', 'TOPSTEP', 'TRADEDAY']);
        $id = $this->faker->numerify('#####-###');

        return [
            'journal_id'   => Journal::factory(),
            'name'         => "{$prefix}-{$id}",
            'connection'   => $this->faker->randomElement(['Rithmic', 'Tradovate', null]),
            'timezone'     => null,
            'account_type' => $this->faker->randomElement(AccountType::cases()),
        ];
    }
}
