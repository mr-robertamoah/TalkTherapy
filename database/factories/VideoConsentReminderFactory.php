<?php

namespace Database\Factories;

use App\Models\Session;
use App\Models\VideoConsentReminder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VideoConsentReminder>
 */
class VideoConsentReminderFactory extends Factory
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
        ];
    }
}
