<?php

namespace App\Actions\SafeguardingException;

use App\Actions\Action;
use App\Models\User;

// SCRUM-384 (extracted for SCRUM-387, whose admin overview counts the same rows): why a pending
// request for a safeguarding exception for $ward is no longer needed -- they have a guardian, an
// exception is in force, they turned 18, or the account was closed. Null while the minor is still
// paused, i.e. the request still needs an answer. $ward is the minor, deleted accounts included.
class GetSafeguardingRequestNoLongerNeededReasonAction extends Action
{
    public function execute(?User $ward): ?string
    {
        if (! $ward || $ward->trashed()) {
            return 'The account was closed.';
        }

        if ($ward->isPausedForMissingGuardian()) {
            return null;
        }

        return match (true) {
            $ward->isAdult() => 'They have turned 18.',
            $ward->hasActiveGuardian() => 'They have a guardian now.',
            $ward->hasActiveSafeguardingException() => 'A safeguarding exception is in force.',
            default => 'They are no longer paused.',
        };
    }
}
