<?php

namespace Database\Factories;

use App\Enums\TherapyPaymentTypeEnum;
use App\Enums\TherapySessionTypeEnum;
use App\Enums\TherapyStatusEnum;
use App\Models\Therapy;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Therapy>
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
            'allow_in_person' => false, // SCRUM-443: in-person excludes minors; tests opt in
            'anonymous' => true,
        ];
    }
}
