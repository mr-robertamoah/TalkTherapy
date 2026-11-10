<?php

namespace App\Actions\User;

use App\Actions\Action;
use App\Enums\RequestTypeEnum;
use App\Models\Request;
use App\Models\User;

// TT-4.11e/SCRUM-306 closeout (qa-engineer finding, second pass): distinct from
// GetPendingAgeVerificationRequestAction, which is deliberately scoped to PENDING only (needed by
// SubmitAgeVerificationAction's own idempotent-reuse logic). The Profile page's "submit a
// statement" / "submit another statement" button label needs a DIFFERENT question answered --
// "has this user ever submitted one at all," regardless of its current status -- since
// "submit another statement" is accurate whether the prior one is still pending, accepted, or
// rejected (a rejected/decided request doesn't retroactively make it as if none was ever sent).
// Scoping the label to PENDING-only left it silently reverting to "submit a statement" the moment
// a request was decided, the same class of misleading-label bug this ticket set out to fix in
// the first place, just shifted to a different state transition.
class HasSubmittedAgeVerificationAction extends Action
{
    public function execute(User $user): bool
    {
        return Request::query()
            ->whereType(RequestTypeEnum::ageVerification->value)
            ->whereFor($user)
            ->exists();
    }
}
