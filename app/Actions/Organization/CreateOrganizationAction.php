<?php

namespace App\Actions\Organization;

use App\Actions\Action;
use App\DTOs\OrganizationDTO;
use App\Enums\OrganizationAdminRoleEnum;
use App\Models\Organization;

class CreateOrganizationAction extends Action
{
    public function execute(OrganizationDTO $dto): Organization
    {
        $organization = Organization::create([
            'name' => $dto->name,
            'legal_name' => $dto->legalName,
            'registration_number' => $dto->registrationNumber,
            'description' => $dto->description,
            'email' => $dto->email,
            'phone' => $dto->phone,
            'is_provider' => (bool) $dto->isProvider,
            'is_consumer' => (bool) $dto->isConsumer,
        ]);

        $organization->admins()->attach($dto->user->id, ['role' => OrganizationAdminRoleEnum::owner->value]);

        CreateOrganizationVerificationRequestAction::new()->execute($organization);

        return $organization->refresh();
    }
}
