<?php

namespace Database\Factories;

use App\Enums\SessionStatusEnum;
use App\Enums\SessionTypeEnum;
use App\Enums\TherapyPaymentTypeEnum;
use App\Models\Session;
use App\Models\Therapy;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Session>
 */
class SessionFactory extends Factory
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
            'about' => $this->faker->sentence(10),
            'start_time' => $this->faker->timezone(),
            'end_time' => $this->faker->timezone(),
            'payment_type' => TherapyPaymentTypeEnum::free->value,
            'type' => SessionTypeEnum::online->value,
            'status' => SessionStatusEnum::in_session_confirmation->value,
            'longitude' => null,
            'latitude' => null,
            'landmark' => null,
            'for_id' => 1,
            'for_type' => Therapy::class,
        ];
    }
}
