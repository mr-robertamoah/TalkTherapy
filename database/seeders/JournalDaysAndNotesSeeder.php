<?php

namespace Database\Seeders;

use App\Models\JournalEntry;
use App\Models\Session;
use App\Models\SessionNote;
use App\Models\Therapy;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

// SCRUM-440: `journal_days_demo` -- a journal long enough for two pages of day notes, some days with several entries
// (one mentions "anxious", for search). SCRUM-438: notes by `your_data_demo_counsellor` on "Your Data Demo Therapy", to
// export. Each part skipped once it's there.
class JournalDaysAndNotesSeeder extends Seeder
{
    use RefusesOutsideLocal;

    public function run(): void
    {
        if ($this->refusedHere()) {
            return;
        }

        $this->journal();
        $this->notes();
    }

    private function journal(): void
    {
        if (User::query()->where('username', 'journal_days_demo')->exists()) {
            return;
        }

        $user = User::factory()->adult()->create([
            'firstName' => 'Journal', 'lastName' => 'Days', 'username' => 'journal_days_demo',
            'email' => 'journal.days.demo@example.com', 'email_verified_at' => now(), 'password' => Hash::make('password'),
        ]);

        foreach (range(0, 15) as $daysAgo) {
            foreach (range(1, $daysAgo % 4 === 0 ? 3 : 1) as $n) {
                JournalEntry::query()->forceCreate([
                    'user_id' => $user->id,
                    'title' => $n === 1 ? null : "Later, part {$n}",
                    'content' => $daysAgo === 2 ? 'Felt anxious before the meeting, but it went fine.' : "Day {$daysAgo}, entry {$n}: a few lines about how today went.",
                    'written_on' => now()->subDays($daysAgo)->toDateString(),
                ]);
            }
        }
    }

    private function notes(): void
    {
        $client = User::query()->where('username', 'your_data_demo')->first();
        $therapy = $client ? Therapy::query()->where('addedby_type', $client->getMorphClass())->where('addedby_id', $client->id)
            ->where('name', 'Your Data Demo Therapy')->first() : null;
        $session = $therapy ? Session::query()->where('for_type', $therapy->getMorphClass())->where('for_id', $therapy->id)->first() : null;

        if (! $session || ! $therapy->counsellor_id || SessionNote::query()->where('session_id', $session->id)->exists()) {
            return;
        }

        foreach (['Client described poor sleep for two weeks; agreed a wind-down routine.', 'Follow up next time on the work situation.'] as $content) {
            SessionNote::query()->forceCreate(['session_id' => $session->id, 'counsellor_id' => $therapy->counsellor_id, 'content' => $content]);
        }
    }
}
