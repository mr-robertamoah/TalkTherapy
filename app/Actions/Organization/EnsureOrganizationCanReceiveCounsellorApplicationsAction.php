<?php

namespace App\Actions\Organization;

use App\Actions\Action;
use App\Exceptions\OrganizationException;
use App\Models\Organization;

class EnsureOrganizationCanReceiveCounsellorApplicationsAction extends Action
{
    // Deliberately ONE generic message covering both "unverified" and "not a provider" --
    // OrganizationController::show() restricts organization details to that org's own admins,
    // so a counsellor-facing endpoint must not let an arbitrary organizationId be probed for
    // its verification/provider status via distinguishable error messages.
    public function execute(Organization $organization): void
    {
        if ($organization->isNotVerified() || ! $organization->is_provider) {
            throw new OrganizationException('You cannot apply to this organization.', 422);
        }
    }
}
