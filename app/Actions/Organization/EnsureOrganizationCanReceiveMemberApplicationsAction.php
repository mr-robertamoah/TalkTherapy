<?php

namespace App\Actions\Organization;

use App\Actions\Action;
use App\Exceptions\OrganizationException;
use App\Models\Organization;

class EnsureOrganizationCanReceiveMemberApplicationsAction extends Action
{
    // Deliberately ONE generic message covering "doesn't exist" (SCRUM-179), "unverified", "not
    // a consumer", and "self-apply disabled" -- mirrors
    // EnsureOrganizationCanReceiveCounsellorApplicationsAction. OrganizationController::show()
    // restricts organization details to that org's own admins, so a user-facing self-apply
    // endpoint must not let an arbitrary organizationId be probed for its existence/
    // verification/consumer/self-apply status via distinguishable error messages or status
    // codes. A nonexistent org used to 404 via a preceding, now-removed standalone
    // EnsureOrganizationExistsAction call -- folded in here instead, same as SCRUM-170/178.
    public function execute(?Organization $organization): void
    {
        if (is_null($organization) || $organization->isNotVerified() || ! $organization->is_consumer || ! $organization->self_apply_enabled) {
            throw new OrganizationException('You cannot apply to this organization.', 422);
        }
    }
}
