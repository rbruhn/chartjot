<?php

namespace Database\Factories;

use App\Enums\InvitationStatus;
use App\Models\Trade;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class TradeInvitationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'trade_id'           => Trade::factory(),
            'invited_user_id'    => User::factory(),
            'invited_by_user_id' => User::factory(),
            'status'             => InvitationStatus::Pending,
        ];
    }

    public function accepted(): static
    {
        return $this->state(['status' => InvitationStatus::Accepted]);
    }
}
