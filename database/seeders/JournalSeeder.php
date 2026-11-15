<?php

namespace Database\Seeders;

use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Database\Seeder;

// SCRUM-36: journal entries for trying the journal -- `your_data_demo` (an adult) and `your_data_demo_ward`, the minor
// whose guardian is `your_data_demo` (who must see none of the ward's). After YourDataSeeder; each account is skipped
// once it has entries. SCRUM-40: `your_data_demo` also has refund emails switched off (left alone once they've chosen).
class JournalSeeder extends Seeder
{
    use RefusesOutsideLocal;

    public function run(): void
    {
        if ($this->refusedHere()) {
            return;
        }

        $this->entries('your_data_demo', [
            [12, 'A heavy week', "Work was a lot this week. I noticed I stopped going for walks.\nTrying to get back to it."],
            [5, null, 'Talked to my counsellor about sleep. Going to try putting my phone away an hour before bed.'],
            [1, 'Small win', 'Went for a walk before work. Felt calmer all morning.'],
        ]);
        User::query()->where('username', 'your_data_demo')->whereNull('notification_channels')
            ->update(['notification_channels' => json_encode(['payments' => ['mail' => false]])]);

        $this->entries('your_data_demo_ward', [
            [3, 'School', 'Exams start next week. My guardian keeps asking how I feel -- I wrote it here instead.'],
        ]);
    }

    private function entries(string $username, array $entries): void
    {
        $user = User::query()->where('username', $username)->first();

        if (! $user || $user->journalEntries()->exists()) {
            return;
        }

        foreach ($entries as [$daysAgo, $title, $content]) {
            JournalEntry::query()->forceCreate([
                'user_id' => $user->id,
                'title' => $title,
                'content' => $content,
                'written_on' => now()->subDays($daysAgo)->toDateString(),
            ]);
        }
    }
}
