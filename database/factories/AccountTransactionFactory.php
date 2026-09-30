<?php

namespace Database\Factories;

use App\Enums\TransactionType;
use App\Models\Account;
use Illuminate\Database\Eloquent\Factories\Factory;

class AccountTransactionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'account_id'  => Account::factory(),
            'type'        => $this->faker->randomElement(TransactionType::cases()),
            'amount'      => $this->faker->randomFloat(2, 100, 5000),
            'occurred_at' => $this->faker->dateTimeBetween('-6 months')->format('Y-m-d'),
        ];
    }
}
