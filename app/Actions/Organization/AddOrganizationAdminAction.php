<?php

namespace App\Actions\Organization;

use App\Actions\Action;
use App\DTOs\OrganizationAdminDTO;
use App\Enums\OrganizationAdminRoleEnum;
use App\Models\Organization;

class AddOrganizationAdminAction extends Action
{
    public function execute(OrganizationAdminDTO $dto): Organization
    {
        $dto->organization->admins()->attach($dto->admin->id, [
            'role' => $dto->role ?? OrganizationAdminRoleEnum::admin->value,
        ]);

        return $dto->organization->refresh();
    }
}
