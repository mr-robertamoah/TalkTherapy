<?php

namespace Database\Factories;

use App\Enums\TherapyPaymentTypeEnum;
use App\Enums\TherapySessionTypeEnum;
use App\Enums\TherapyStatusEnum;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Therapy>
 */
class TherapyFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'session_type' => TherapySessionTypeEnum::once->value,
            'payment_type' => TherapyPaymentTypeEnum::free->value,
            'status' => TherapyStatusEnum::in_session->value,
            'name' => $this->faker->name,
            'background_story' => $this->faker->sentences(10, true),
            'public' => true,
            'allow_in_person' => true,
            'anonymous' => true,
        ];
    }
}
