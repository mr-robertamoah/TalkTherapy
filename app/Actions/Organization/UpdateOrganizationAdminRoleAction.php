<?php

namespace App\Actions\Organization;

use App\Actions\Action;
use App\DTOs\OrganizationAdminDTO;
use App\Models\Organization;

class UpdateOrganizationAdminRoleAction extends Action
{
    public function execute(OrganizationAdminDTO $dto): Organization
    {
        $dto->organization->admins()->updateExistingPivot($dto->admin->id, [
            'role' => $dto->role,
        ]);

        return $dto->organization->refresh();
    }
}
