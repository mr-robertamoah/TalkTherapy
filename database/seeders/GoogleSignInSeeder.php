<?php

namespace Database\Seeders;

use App\Models\User;
use App\Support\TwoFactorCodes;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

// SCRUM-453 (Google sign-in, SCRUM-112): two accounts already connected to Google. Their Google ids are made up, so
// "Continue with Google" reaches them only with a real Google client whose test account's id (`sub`) is set to match
// (or in tests). Each skipped once it exists.
// - google_only_demo: no password at all -- Google is its only way in.
// - google_2fa_demo: password "password", connected to Google, and two-factor on (DatabaseSeeder's fixed demo key and
//   recovery codes) -- a Google sign-in still asks for the code.
// - google_only_no_email_demo (SCRUM-456): no password, no email, two-factor on -- Google is the only way in, so the
//   admin list's two-factor reset explains why it must be done on the server, and `user:two-factor-reset` asks first.
class GoogleSignInSeeder extends Seeder
{
    use RefusesOutsideLocal;

    public const GOOGLE_ONLY_SUB = 'demo-google-sub-1';

    public const GOOGLE_2FA_SUB = 'demo-google-sub-2';

    public const GOOGLE_ONLY_NO_EMAIL_SUB = 'demo-google-sub-3';

    public function run(): void
    {
        if ($this->refusedHere()) {
            return;
        }

        $this->person('google_only_demo', self::GOOGLE_ONLY_SUB, null);

        $this->withTwoFactor($this->person('google_2fa_demo', self::GOOGLE_2FA_SUB, Hash::make('password')));

        $this->withTwoFactor($this->person('google_only_no_email_demo', self::GOOGLE_ONLY_NO_EMAIL_SUB, null, withEmail: false));
    }

    private function withTwoFactor(?User $user): void
    {
        $user?->forceFill([
            'two_factor_secret' => DatabaseSeeder::TWO_FACTOR_DEMO_SECRET,
            'two_factor_recovery_codes' => array_map(fn (string $code) => TwoFactorCodes::hash($code), DatabaseSeeder::TWO_FACTOR_DEMO_RECOVERY_CODES),
            'two_factor_confirmed_at' => now(),
        ])->save();
    }

    private function person(string $username, string $sub, ?string $password, bool $withEmail = true): ?User
    {
        if (User::query()->withTrashed()->where('username', $username)->orWhere('google_id', $sub)->exists()) {
            return null;
        }

        $user = User::factory()->adult()->create([
            'firstName' => 'Google', 'lastName' => 'Demo', 'username' => $username,
            'email' => $withEmail ? str_replace('_', '.', $username).'@example.com' : null,
            'email_verified_at' => $withEmail ? now() : null,
            'password' => $password,
        ]);
        // Not fillable (SCRUM-452), so set directly.
        $user->forceFill(['google_id' => $sub, 'google_linked_at' => now()])->save();

        return $user;
    }
}
