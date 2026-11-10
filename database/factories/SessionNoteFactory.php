<?php

namespace Database\Factories;

use App\Models\Counsellor;
use App\Models\Session;
use App\Models\SessionNote;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SessionNote>
 */
class SessionNoteFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'content' => $this->faker->sentence(10),
            'session_id' => Session::factory(),
            'counsellor_id' => Counsellor::factory(['user_id' => User::factory()]),
        ];
    }
}
