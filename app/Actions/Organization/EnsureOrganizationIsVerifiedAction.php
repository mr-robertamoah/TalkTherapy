<?php

namespace App\Actions\Organization;

use App\Actions\Action;
use App\Exceptions\OrganizationException;
use App\Models\Organization;

class EnsureOrganizationIsVerifiedAction extends Action
{
    public function execute(Organization $organization): void
    {
        if ($organization->isNotVerified()) {
            throw new OrganizationException('This organization has not yet been verified by the platform.', 422);
        }
    }
}
