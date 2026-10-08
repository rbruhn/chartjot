<?php

namespace Database\Factories;

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    public function definition(): array
    {
        return [
            'name'              => fake()->name(),
            'email'             => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password'          => static::$password ??= Hash::make('password'),
            'remember_token'    => Str::random(10),
            'status'            => UserStatus::Active,
            'is_admin'          => false,
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    public function pending(): static
    {
        return $this->state(['status' => UserStatus::Pending]);
    }

    public function admin(): static
    {
        return $this->state(['is_admin' => true, 'status' => UserStatus::Active]);
    }

    /** #115: the read-only demo account. */
    public function demo(): static
    {
        return $this->state(['is_demo' => true, 'status' => UserStatus::Active]);
    }
}
