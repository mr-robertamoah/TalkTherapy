<?php

namespace Database\Factories;

use App\Enums\TherapyPaymentTypeEnum;
use App\Enums\TherapySessionTypeEnum;
use App\Enums\TherapyStatusEnum;
use App\Models\GroupTherapy;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GroupTherapy>
 */
class GroupTherapyFactory extends Factory
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
            'about' => $this->faker->sentences(10, true),
            'public' => true,
            'anonymous' => true,
            'allow_anyone' => true,
            'allow_in_person' => true,
        ];
    }
}
