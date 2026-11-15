<?php

namespace Database\Seeders;

use App\Models\OutcomeCheckIn;
use App\Models\Therapy;
use App\Models\User;
use App\Support\OutcomeMeasures\Phq9;
use Illuminate\Database\Seeder;

// SCRUM-37: check-ins on "Your Data Demo Therapy" (client `your_data_demo`, counsellor `your_data_demo_counsellor`):
// four answered PHQ-9s for the chart -- the latest with a self-harm answer not yet seen by the counsellor -- and one
// GAD-7 waiting for an answer. After YourDataSeeder; skipped once the therapy has check-ins.
class CheckInSeeder extends Seeder
{
    use RefusesOutsideLocal;

    public function run(): void
    {
        if ($this->refusedHere()) {
            return;
        }

        $client = User::query()->where('username', 'your_data_demo')->first();
        $therapy = $client ? Therapy::query()->where('addedby_type', $client->getMorphClass())->where('addedby_id', $client->id)
            ->where('name', 'Your Data Demo Therapy')->first() : null;

        if (! $therapy?->counsellor_id || OutcomeCheckIn::query()->where('therapy_id', $therapy->id)->exists()) {
            return;
        }

        $phq9 = new Phq9;
        foreach ([[60, [2, 2, 2, 2, 1, 2, 1, 1, 0]], [40, [2, 1, 2, 1, 1, 1, 1, 0, 0]], [20, [1, 1, 1, 1, 1, 1, 0, 0, 0]], [5, [1, 1, 1, 0, 1, 0, 0, 0, 1]]] as [$daysAgo, $answers]) {
            $score = $phq9->score($answers);
            $this->checkIn($therapy, $client, 'phq9', $daysAgo, [
                'status' => OutcomeCheckIn::COMPLETED,
                'completed_at' => now()->subDays($daysAgo)->addHours(3),
                'answers' => $answers,
                'total_score' => (string) $score,
                'band' => $phq9->band($score),
                'needs_attention' => $phq9->needsAttention($answers),
            ]);
        }

        $this->checkIn($therapy, $client, 'gad7', 1, ['status' => OutcomeCheckIn::REQUESTED, 'open_key' => OutcomeCheckIn::openKeyFor($therapy->id, 'gad7')]);
    }

    private function checkIn(Therapy $therapy, User $client, string $measure, int $daysAgo, array $attributes): void
    {
        $checkIn = new OutcomeCheckIn;
        $checkIn->forceFill([
            'therapy_id' => $therapy->id,
            'counsellor_id' => $therapy->counsellor_id,
            'client_user_id' => $client->id,
            'measure' => $measure,
            'measure_version' => 1,
            'requested_at' => now()->subDays($daysAgo),
            'expires_at' => now()->subDays($daysAgo)->addDays((int) config('check_ins.expires_days')),
            ...$attributes,
        ])->save();
    }
}
