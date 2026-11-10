<?php

namespace App\Actions\Organization;

use App\Actions\Action;
use App\Exceptions\OrganizationException;
use App\Models\Organization;

class EnsureOrganizationCanReceiveCounsellorApplicationsAction extends Action
{
    // Deliberately ONE generic message covering "doesn't exist" (SCRUM-179), "unverified", and
    // "not a provider" -- OrganizationController::show() restricts organization details to that
    // org's own admins, so a counsellor-facing endpoint must not let an arbitrary organizationId
    // be probed for its existence/verification/provider status via distinguishable error
    // messages or status codes. A nonexistent org used to 404 via a preceding, now-removed
    // standalone EnsureOrganizationExistsAction call -- folded in here instead, same as SCRUM-170/178.
    public function execute(?Organization $organization): void
    {
        if (is_null($organization) || $organization->isNotVerified() || ! $organization->is_provider) {
            throw new OrganizationException('You cannot apply to this organization.', 422);
        }
    }
}
