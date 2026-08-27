<?php

namespace App\Actions\Organization;

use App\Actions\Action;
use App\Exceptions\OrganizationException;
use App\Models\Organization;

class EnsureOrganizationIsConsumerAction extends Action
{
    public function execute(Organization $organization): void
    {
        if (! $organization->is_consumer) {
            throw new OrganizationException('This organization does not sponsor members.', 422);
        }
    }
}
