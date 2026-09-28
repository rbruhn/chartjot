<?php

namespace Database\Factories;

use App\Enums\ScreenshotSource;
use App\Models\Trade;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class TradeScreenshotFactory extends Factory
{
    public function definition(): array
    {
        return [
            'trade_id'    => Trade::factory(),
            'disk'        => 'private',
            'path'        => 'screenshots/'.date('Y/m/').Str::random(40).'.png',
            'caption'     => $this->faker->optional()->sentence(6),
            'mime_type'   => 'image/png',
            'bytes'       => $this->faker->numberBetween(200_000, 2_000_000),
            'captured_at' => $this->faker->dateTimeBetween('-90 days', 'now'),
            'source'      => ScreenshotSource::Nt8,
        ];
    }

    public function manual(): static
    {
        return $this->state(['source' => ScreenshotSource::ManualUpload]);
    }
}
