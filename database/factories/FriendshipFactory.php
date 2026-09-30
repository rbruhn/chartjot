<?php

namespace Database\Factories;

use App\Enums\FriendshipStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class FriendshipFactory extends Factory
{
    public function definition(): array
    {
        return [
            'requester_id' => User::factory(),
            'recipient_id' => User::factory(),
            'status'       => FriendshipStatus::Pending,
        ];
    }

    public function accepted(): static
    {
        return $this->state(['status' => FriendshipStatus::Accepted]);
    }
}
