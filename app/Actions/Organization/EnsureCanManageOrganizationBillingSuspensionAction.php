<?php

namespace App\Actions\Organization;

use App\Actions\Action;
use App\Exceptions\OrganizationException;
use App\Models\User;

// TT-7.3b-followup/SCRUM-245: platform-admin-only, deliberately NOT the organization's own admin
// -- lifting a suspension (or retrying its failed settlement) is a trust decision made by staff
// once they've confirmed the org's payment method/outstanding balance out-of-band, mirroring
// Organization::verify()'s own "a platform decision, not self-service" precedent. Letting an org
// unsuspend itself would give the enforcement mechanism no real teeth.
class EnsureCanManageOrganizationBillingSuspensionAction extends Action
{
    public function execute(?User $user): void
    {
        if (is_null($user) || $user->isNotAdmin()) {
            throw new OrganizationException('You are not authorized to manage an organization\'s billing suspension.', 403);
        }
    }
}
