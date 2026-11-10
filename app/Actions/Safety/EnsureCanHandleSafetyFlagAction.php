<?php

namespace App\Actions\Safety;

use App\Actions\Action;
use App\Exceptions\SafetyFlagException;
use App\Models\SafetyFlag;
use App\Models\User;

// SCRUM-32: an administrator acting on a safety flag -- an administrator not involved in it
// (SafetyFlag::involvesAdmin); for the client's identity and the reason, the one handling it. Missing,
// involved and not-an-administrator all get the same answer.
class EnsureCanHandleSafetyFlagAction extends Action
{
    public const NOT_FOUND = 'This safety alert isn\'t available.';

    public const NOT_HANDLER = 'Only the administrator handling this safety alert can do that. Acknowledge it first.';

    public function execute(?User $admin, ?SafetyFlag $flag, bool $handler = false): SafetyFlag
    {
        if (! $admin?->isAdmin() || ! $flag || $flag->involvesAdmin($admin)) {
            throw new SafetyFlagException(self::NOT_FOUND, 404);
        }

        if ($handler && ! $flag->isHandledBy($admin)) {
            throw new SafetyFlagException(self::NOT_HANDLER, 403);
        }

        return $flag;
    }
}
