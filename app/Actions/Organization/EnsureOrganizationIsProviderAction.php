<?php

namespace App\Actions\Organization;

use App\Actions\Action;
use App\Exceptions\OrganizationException;
use App\Models\Organization;

class EnsureOrganizationIsProviderAction extends Action
{
    public function execute(Organization $organization): void
    {
        if (! $organization->is_provider) {
            throw new OrganizationException('This organization does not offer counsellor services.', 422);
        }
    }
}
