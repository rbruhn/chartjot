<?php

namespace Database\Factories;

use App\Models\Journal;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<Journal>
 */
class JournalFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => fn (): int => User::withoutEvents(
                fn (): int => User::factory()->create()->id,
            ),
            'name' => fake()->name()."'s Trade Journal",
            'account_name' => fake()->bothify('DEMO########'),
            'timezone' => 'America/New_York',
            'ingest_token_hash' => Hash::make(Str::random(64)),
        ];
    }
}
