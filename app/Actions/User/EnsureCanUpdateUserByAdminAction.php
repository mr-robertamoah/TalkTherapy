<?php

namespace App\Actions\User;

use App\Actions\Action;
use App\Exceptions\UserException;
use App\Models\User;

// SCRUM-425: what an administrator may change on someone else's account from the admin user edit. The email is how an
// account is taken over ("forgot password" goes there), so: only a super administrator changes it, never on an
// administrator's account (theirs is changed on their own profile, with their password), and never to nothing (that
// removes the way back in). Ticking an address verified is a super administrator's too (security review: otherwise an
// ordinary admin could finish a change by verifying the new address). Administrators' accounts are edited only by a
// super administrator, or by themselves.
class EnsureCanUpdateUserByAdminAction extends Action
{
    public function execute(User $admin, User $target, ?string $newEmail, bool $verified): void
    {
        if ($target->isAdmin() && ! $admin->is($target) && ! $admin->isSuperAdmin()) {
            throw new UserException('Only a super administrator can edit an administrator\'s account.', 403);
        }

        if (self::changesEmail($target, $newEmail)) {
            if (! $admin->isSuperAdmin()) {
                throw new UserException('Only a super administrator can change someone\'s email.', 403);
            }

            if ($target->isAdmin()) {
                throw new UserException('An administrator changes their own email, from their profile.', 422);
            }

            if ($newEmail === null) {
                throw new UserException('An email can be changed here, but not removed: it\'s how they get back into their account.', 422);
            }
        } elseif ($verified && ! $target->email_verified_at && ! $admin->isSuperAdmin()) {
            throw new UserException('Only a super administrator can mark an email verified.', 403);
        }
    }

    // A change of address -- not just of capitals (the same mailbox; the unique check treats them alike too).
    public static function changesEmail(User $target, ?string $newEmail): bool
    {
        return mb_strtolower((string) $newEmail) !== mb_strtolower((string) $target->email);
    }
}
