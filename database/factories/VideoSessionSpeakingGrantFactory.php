<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\VideoSessionParticipant;
use Illuminate\Database\Eloquent\Factories\Factory;

class VideoSessionSpeakingGrantFactory extends Factory
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
            'granted_by_user_id' => User::factory(),
            'granted_at' => now(),
        ];
    }
}
