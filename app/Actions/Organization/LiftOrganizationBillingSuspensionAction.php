<?php

namespace App\Actions\Organization;

use App\Actions\Action;
use App\Exceptions\OrganizationException;
use App\Models\Organization;
use App\Models\User;

// TT-7.3b-followup/SCRUM-245: the manual "resolve this" path SCRUM-238 deliberately left
// unbuilt -- no dunning/auto-retry existed yet at the time, so there was nothing for an automatic
// lift to key off. Idempotent: a second call against an already-unsuspended org is a harmless
// no-op, not an error (mirrors this codebase's other single-current-state-flag actions, e.g.
// Organization::verify() itself has no "already verified" guard either).
class LiftOrganizationBillingSuspensionAction extends Action
{
    public function execute(?User $user, ?Organization $organization): Organization
    {
        EnsureCanManageOrganizationBillingSuspensionAction::new()->execute($user);

        if (is_null($organization)) {
            throw new OrganizationException('Organization not found.', 404);
        }

        if ($organization->isBillingSuspended()) {
            $organization->resumeBilling();
        }

        return $organization->fresh();
    }
}
