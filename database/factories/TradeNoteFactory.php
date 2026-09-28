<?php

namespace Database\Factories;

use App\Enums\NotePhase;
use App\Models\Trade;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class TradeNoteFactory extends Factory
{
    public function definition(): array
    {
        return [
            'trade_id'    => Trade::factory(),
            'created_by'  => User::factory(),
            'body'        => $this->faker->sentences(2, true),
            'phase'       => $this->faker->randomElement(NotePhase::cases()),
            'occurred_at' => $this->faker->dateTimeBetween('-90 days', 'now'),
        ];
    }

    public function preTrade(): static
    {
        return $this->state(['phase' => NotePhase::PreTrade]);
    }

    public function inTrade(): static
    {
        return $this->state(['phase' => NotePhase::InTrade]);
    }

    public function postTrade(): static
    {
        return $this->state(['phase' => NotePhase::PostTrade]);
    }
}
