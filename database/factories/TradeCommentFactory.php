<?php

namespace Database\Factories;

use App\Models\Trade;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class TradeCommentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'trade_id'          => Trade::factory(),
            'user_id'           => User::factory(),
            'parent_comment_id' => null,
            'body'              => $this->faker->sentence(),
        ];
    }
}
