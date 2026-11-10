<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\VideoSession;
use App\Models\VideoSessionParticipant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VideoSessionParticipant>
 */
class VideoSessionParticipantFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'video_session_id' => VideoSession::factory(),
            'participant_type' => User::class,
            'participant_id' => User::factory(),
            'joined_at' => now(),
        ];
    }
}
