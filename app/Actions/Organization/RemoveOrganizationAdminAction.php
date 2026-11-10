<?php

namespace App\Actions\Organization;

use App\Actions\Action;
use App\DTOs\OrganizationAdminDTO;
use App\Models\Organization;

class RemoveOrganizationAdminAction extends Action
{
    public function execute(OrganizationAdminDTO $dto): Organization
    {
        $dto->organization->admins()->detach($dto->admin->id);

        return $dto->organization->refresh();
    }
}
