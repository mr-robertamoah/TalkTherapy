<?php

namespace Database\Factories;

use App\Models\VideoSessionParticipant;
use Illuminate\Database\Eloquent\Factories\Factory;

class VideoSessionHandRaiseFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'video_session_participant_id' => VideoSessionParticipant::factory(),
            'raised_at' => now(),
        ];
    }
}
