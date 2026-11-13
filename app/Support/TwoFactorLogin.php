<?php

namespace App\Support;

use App\Http\Middleware\EndStaleSessions;
use App\Models\User;
use Illuminate\Http\Request;

// SCRUM-34b: a sign-in waiting for its second step -- the password was right, the code not yet given. Only the account
// id, "remember me", an expiry and a fingerprint of the password and two-factor state (so a password reset meanwhile
// cancels it) are kept in the session -- never the password; nobody is signed in meanwhile.
final class TwoFactorLogin
{
    private const KEY = 'two_factor.login';

    public const MINUTES = 5;

    public static function begin(Request $request, User $user, bool $remember): void
    {
        // A new session id, so one fixed before sign-in can't be carried into the second step.
        $request->session()->regenerate();
        $request->session()->put(self::KEY, [
            'user_id' => $user->id,
            'remember' => $remember,
            'expires_at' => now()->addMinutes(self::MINUTES)->getTimestamp(),
            'fingerprint' => EndStaleSessions::fingerprint($user),
        ]);
    }

    // The account waiting for a code, or null (none, expired, or two-factor since turned off).
    public static function user(Request $request): ?User
    {
        $pending = $request->session()->get(self::KEY);

        if (! is_array($pending) || ($pending['expires_at'] ?? 0) < now()->getTimestamp()) {
            return null;
        }

        $user = User::find($pending['user_id'] ?? null);

        return $user?->hasTwoFactorEnabled() && hash_equals((string) ($pending['fingerprint'] ?? ''), EndStaleSessions::fingerprint($user)) ? $user : null;
    }

    public static function remember(Request $request): bool
    {
        return (bool) ($request->session()->get(self::KEY)['remember'] ?? false);
    }

    public static function forget(Request $request): void
    {
        $request->session()->forget(self::KEY);
    }
}
