<?php

namespace App\Actions\Auth;

use App\Actions\Action;
use App\DTOs\GoogleAccount;
use App\Models\BlockedGoogleIdentity;
use App\Models\User;
use App\Notifications\GoogleSignInChangedNotification;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// SCRUM-455 (Google sign-in, SCRUM-112): connects the Google account the signed-in person just chose (by its id, `sub`)
// to their account -- never one found by email. A new way in: other sessions end (session_version, not
// logoutOtherDevices -- Google, not a password, confirmed this last step), "remember me" elsewhere stops working, and
// the owner is told. Returns null when connected, else a refusal written for people.
class ConnectGoogleAccountAction extends Action
{
    public const UNVERIFIED = "Google hasn't confirmed the email address on that Google account, so it can't be connected. Please choose another Google account.";

    // The same words whoever holds it -- a closed account included.
    public const TAKEN = "That Google account can't be connected to your account. Please choose another Google account.";

    public const ALREADY_CONNECTED = 'Your account is already connected to a different Google account. Disconnect it first.';

    public function execute(User $user, GoogleAccount $google): ?string
    {
        if (! $google->emailVerified) {
            return self::UNVERIFIED;
        }

        try {
            $refusal = DB::transaction(function () use ($user, $google) {
                $locked = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

                if ($locked->google_id === $google->sub) {
                    return false;
                }

                if (filled($locked->google_id)) {
                    return self::ALREADY_CONNECTED;
                }

                // SCRUM-456: a Google account whose account an administrator closed gets the same words as a taken one.
                if (BlockedGoogleIdentity::isBlocked($google->sub)
                    || User::query()->withTrashed()->whereKeyNot($locked->id)->where('google_id', $google->sub)->exists()) {
                    return self::TAKEN;
                }

                $locked->forceFill([
                    'google_id' => $google->sub,
                    'google_linked_at' => now(),
                    'session_version' => (int) $locked->session_version + 1,
                    'remember_token' => Str::random(60),
                ])->save();

                $locked->notify(new GoogleSignInChangedNotification(GoogleSignInChangedNotification::CONNECTED));

                return null;
            });
        } catch (UniqueConstraintViolationException) {
            // Connected elsewhere at the same moment.
            return self::TAKEN;
        }

        // The signed-in model takes the new session version, so this session keeps its sign-in (EndStaleSessions).
        $user->refresh();

        return $refusal === false ? null : $refusal;
    }
}
