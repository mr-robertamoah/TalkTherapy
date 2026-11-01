<?php

namespace App\Actions\Suspension;

use App\Actions\Action;
use App\Exceptions\SuspensionException;
use App\Models\User;

// SCRUM-363 (user's decision, 2026-11-01): while any suspension is in force, a person can't delete
// their own account or counsellor profile -- otherwise a suspension could be escaped by deleting
// the account and signing up again with the same email. An administrator deleting it is
// unaffected.
class EnsureCanDeleteOwnAccountWhileSuspendedAction extends Action
{
    public const SUSPENDED = "You can't delete your account while you are suspended. You can do so once the suspension ends.";

    public function execute(User $user): void
    {
        if ($user->forgetActiveSuspension()->anyActiveSuspension()) {
            throw new SuspensionException(self::SUSPENDED, 403);
        }
    }
}
