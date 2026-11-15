<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;

// SCRUM-433: two accounts whose email was removed, so their last verified address is remembered -- one remembered 3
// days ago ("This wasn't me" would put it back), one with no date (remembered before SCRUM-433: it wouldn't). Super
// administrators see "previously verified: … (remembered …)" under the email on the users list. Each skipped once it
// exists.
class RestoreGuardSeeder extends Seeder
{
    use RefusesOutsideLocal;

    public function run(): void
    {
        if ($this->refusedHere()) {
            return;
        }

        $this->person('restore_guard_recent', now()->subDays(3));
        $this->person('restore_guard_old', null);
    }

    private function person(string $username, ?Carbon $rememberedAt): void
    {
        if (User::query()->where('username', $username)->exists()) {
            return;
        }

        $user = User::factory()->adult()->create([
            'firstName' => 'Restore', 'lastName' => 'Guard', 'username' => $username,
            'email' => null, 'email_verified_at' => null, 'password' => Hash::make('password'),
        ]);
        $user->forceFill(['last_verified_email' => str_replace('_', '.', $username).'@example.com', 'last_verified_email_at' => $rememberedAt])->saveQuietly();
    }
}
