<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Counsellor>
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
            'email_verified_at' => now(),
        ];
    }
}
