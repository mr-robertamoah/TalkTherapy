<?php

namespace App\Actions\Auth;

use App\Actions\Action;
use App\DTOs\GoogleAccount;
use App\DTOs\GoogleAccountResolution;
use App\Models\User;

// SCRUM-453 (Google sign-in, SCRUM-112): which account, if any, a Google sign-in is for. Only an account already
// connected to this Google account (by Google's id, `sub`) -- never one found by email (the user's decision: that
// would hand the account to whoever controls the mailbox). SCRUM-454: connected to nothing, it starts a new account.
class ResolveGoogleAccountAction extends Action
{
    // A closed or anonymised account still holding the Google id: it is never signed in, and this says nothing about it.
    public const NOT_CONNECTED = "There's no TalkTherapy account connected to this Google account. If you have an account, sign in with your username or email and password, then connect Google from your profile.";

    public const UNVERIFIED = "Google hasn't confirmed the email address on that Google account, so it can't be used to sign in here. Please sign in another way.";

    public function execute(GoogleAccount $google): GoogleAccountResolution
    {
        if (! $google->emailVerified) {
            return GoogleAccountResolution::refuse(self::UNVERIFIED);
        }

        $user = User::query()->withTrashed()->where('google_id', $google->sub)->first();

        // SCRUM-454: by `sub` only -- an email matching an account here is never linked; the new account just goes
        // without that email (PendingGoogleSignUp).
        if (! $user) {
            return GoogleAccountResolution::startSignUp();
        }

        // A closed or anonymised account is never signed in or revived. Closing clears the link (SCRUM-452); this is
        // the guard if one ever didn't.
        if ($user->trashed() || $user->anonymised_at) {
            return GoogleAccountResolution::refuse(self::NOT_CONNECTED);
        }

        return GoogleAccountResolution::signIn($user);
    }
}
