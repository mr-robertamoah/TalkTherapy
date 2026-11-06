<?php

namespace Database\Factories;

use App\Models\Counsellor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Counsellor>
 */
class CounsellorFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'email' => $this->faker->email,
            'about' => $this->faker->sentence,
            'verified_at' => now(),
            // SCRUM-31: verified, and not ending soon. A test passing 'verified_at' => null gets an
            // unverified counsellor (the end date alone means nothing).
            'verification_expires_at' => now()->addYear(),
            'email_verified_at' => now(),
        ];
    }

    // SCRUM-30/31: never verified.
    public function unverified(): static
    {
        return $this->state(['verified_at' => null, 'verification_expires_at' => null]);
    }

    // SCRUM-31: was verified; it ended $daysAgo days ago.
    public function lapsed(int $daysAgo = 3): static
    {
        return $this->state(['verified_at' => now()->subYear(), 'verification_expires_at' => now()->subDays($daysAgo)]);
    }

    // SCRUM-31: verified, ending in $days days.
    public function verificationEndingIn(int $days): static
    {
        return $this->state(['verified_at' => now()->subYear(), 'verification_expires_at' => now()->addDays($days)]);
    }
}
