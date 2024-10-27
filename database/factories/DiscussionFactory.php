<?php

namespace Database\Factories;

use App\Enums\DiscussionStatusEnum;
use App\Models\Therapy;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Discussion>
 */
class DiscussionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->name,
            'description' => $this->faker->sentence(10),
            'start_time' => $this->faker->timezone(),
            'end_time' => $this->faker->timezone(),
            'status' => DiscussionStatusEnum::in_session_confirmation->value,
            'for_id' => 1,
            'for_type' => Therapy::class,
        ];
    }
}
