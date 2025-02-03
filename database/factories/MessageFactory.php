<?php

namespace Database\Factories;

use App\Enums\MessageStatusEnum;
use App\Enums\MessageTypeEnum;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Message>
 */
class MessageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => $this->faker->randomElement(
                MessageTypeEnum::cases()
            ),
            'content' => $this->faker->sentence,
            'status' => MessageStatusEnum::sent->value,
        ];
    }
}
