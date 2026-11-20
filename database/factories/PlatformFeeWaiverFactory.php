<?php

namespace Database\Factories;

use App\Models\Counsellor;
use App\Models\PlatformFeeWaiver;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlatformFeeWaiver>
 */
class PlatformFeeWaiverFactory extends Factory
{
    // Platform-wide, started a day ago, open-ended: in force now.
    public function definition(): array
    {
        return [
            'counsellor_id' => null,
            'starts_at' => now()->subDay(),
            'ends_at' => null,
            'reason' => $this->faker->sentence(),
        ];
    }

    public function forCounsellor(Counsellor $counsellor): static
    {
        return $this->state(fn () => ['counsellor_id' => $counsellor->id]);
    }

    // [$startsAt, $endsAt) -- $endsAt null leaves it open-ended.
    public function between(CarbonInterface $startsAt, ?CarbonInterface $endsAt): static
    {
        return $this->state(fn () => ['starts_at' => $startsAt, 'ends_at' => $endsAt]);
    }

    public function ended(): static
    {
        return $this->state(fn () => ['starts_at' => now()->subMonth(), 'ends_at' => now()->subDay()]);
    }

    public function upcoming(): static
    {
        return $this->state(fn () => ['starts_at' => now()->addDay(), 'ends_at' => now()->addMonth()]);
    }
}
