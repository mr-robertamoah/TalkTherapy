<?php

namespace App\Actions\Organization;

use App\Actions\Action;
use App\Exceptions\OrganizationException;
use App\Models\Organization;

class EnsureOrganizationCanReceiveMemberApplicationsAction extends Action
{
    // Deliberately ONE generic message covering "unverified", "not a consumer", and
    // "self-apply disabled" -- mirrors EnsureOrganizationCanReceiveCounsellorApplicationsAction.
    // OrganizationController::show() restricts organization details to that org's own admins,
    // so a user-facing self-apply endpoint must not let an arbitrary organizationId be probed
    // for its verification/consumer/self-apply status via distinguishable error messages.
    public function execute(Organization $organization): void
    {
        if ($organization->isNotVerified() || ! $organization->is_consumer || ! $organization->self_apply_enabled) {
            throw new OrganizationException('You cannot apply to this organization.', 422);
        }
    }
}
