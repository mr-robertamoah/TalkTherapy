<?php

namespace App\Support;

use App\DTOs\GoogleAccount;
use App\Models\User;
use Illuminate\Http\Request;

// SCRUM-454 (Google sign-in, SCRUM-112): a new account waiting for "Finish setting up" -- Google said who it is, but no
// account exists until the form is sent (so there are no half-made ones). Only Google's id and, when it can be used, its
// email are kept, in the session, for a short while. Google's name and photo are never kept (the user's decision).
final class PendingGoogleSignUp
{
    private const KEY = 'google.sign_up';

    // Short, and cleared whenever the sign-in or registration page is shown or someone signs in (security review:
    // shared devices), so one person's unfinished sign-up isn't left for the next to finish.
    public const MINUTES = 10;

    public static function begin(Request $request, GoogleAccount $google): void
    {
        // A new session id, so one fixed before the sign-up can't be carried into it.
        $request->session()->regenerate();
        $request->session()->put(self::KEY, [
            'sub' => $google->sub,
            'email' => self::usableEmail($google),
            'expires_at' => now()->addMinutes(self::MINUTES)->getTimestamp(),
        ]);
    }

    /**
     * The sign-up waiting to be finished, or null (none, or expired).
     *
     * @return array{sub: string, email: ?string}|null
     */
    public static function current(Request $request): ?array
    {
        $pending = $request->session()->get(self::KEY);

        if (! is_array($pending) || ! is_string($pending['sub'] ?? null) || ($pending['expires_at'] ?? 0) < now()->getTimestamp()) {
            return null;
        }

        return ['sub' => $pending['sub'], 'email' => $pending['email'] ?? null];
    }

    public static function forget(Request $request): void
    {
        $request->session()->forget(self::KEY);
    }

    // The new account takes Google's email only when Google confirmed it and no account here uses it in any way -- its
    // address (closed accounts included), one it is changing to, or the verified one it remembers (SCRUM-427). Otherwise
    // it goes without one; nothing tells the person which, so it confirms nothing about anyone's account.
    public static function emailIsFree(string $email): bool
    {
        // Both spellings: MySQL compares without case already, and this keeps SQLite (the tests) honest for the usual one.
        $spellings = array_values(array_unique([$email, mb_strtolower($email)]));

        return ! User::query()->withTrashed()
            ->where(fn ($query) => $query->whereIn('email', $spellings)->orWhereIn('pending_email', $spellings)->orWhereIn('last_verified_email', $spellings))
            ->exists();
    }

    private static function usableEmail(GoogleAccount $google): ?string
    {
        $email = $google->email;

        // Only an address Google vouches for (Gmail or Workspace), since it is kept as already confirmed. Otherwise the
        // account goes without one -- the same as a taken address, so nothing tells the person which.
        return $google->emailIsAuthoritative() && filter_var($email, FILTER_VALIDATE_EMAIL) && mb_strlen($email) <= 255 && self::emailIsFree($email)
            ? $email
            : null;
    }
}
