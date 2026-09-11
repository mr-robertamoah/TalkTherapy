<?php

namespace Database\Factories;

use App\Models\Session;
use App\Models\VideoSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VideoSession>
 */
class VideoSessionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'session_id' => Session::factory(),
            'provider' => 'daily',
            'provider_room_id' => 'session-1-1',
            'provider_meta' => [],
            'started_at' => now(),
        ];
    }
}
