<?php

namespace App\Actions\Auth;

use App\Actions\Action;
use App\Models\User;
use App\Notifications\GoogleSignInChangedNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// SCRUM-455 (Google sign-in, SCRUM-112): lets go of the account's Google account (the request checked the password).
// Never for an account without a password -- Google would be its only way in. Other sessions end (session_version),
// "remember me" elsewhere stops working, and the owner is told. Returns null when done, else a refusal for people.
class DisconnectGoogleAccountAction extends Action
{
    public const NEEDS_PASSWORD = 'Your account has no password, so Google is your only way to sign in. Set a password first, then you can disconnect Google.';

    public const NOT_CONNECTED = 'Your account is not connected to Google.';

    public function execute(User $user): ?string
    {
        $refusal = DB::transaction(function () use ($user) {
            $locked = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

            if (! $locked->hasPassword()) {
                return self::NEEDS_PASSWORD;
            }

            if (blank($locked->google_id)) {
                return self::NOT_CONNECTED;
            }

            $locked->forgetGoogle()->forceFill([
                'session_version' => (int) $locked->session_version + 1,
                'remember_token' => Str::random(60),
            ])->save();

            $locked->notify(new GoogleSignInChangedNotification(GoogleSignInChangedNotification::DISCONNECTED));

            return null;
        });

        // The signed-in model takes the new session version, so this session keeps its sign-in (EndStaleSessions).
        $user->refresh();

        return $refusal;
    }
}
