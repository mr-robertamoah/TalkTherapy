<?php

namespace Database\Factories;

use App\Models\Counsellor;
use App\Models\Message;
use App\Models\MessageNote;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MessageNote>
 */
class MessageNoteFactory extends Factory
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
            'message_id' => Message::factory(),
            'counsellor_id' => Counsellor::factory(['user_id' => User::factory()]),
        ];
    }
}
