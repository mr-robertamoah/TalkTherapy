<?php

namespace App\Actions\Organization;

use App\Actions\Action;
use App\DTOs\OrganizationDTO;
use App\Models\Organization;

class UpdateOrganizationAction extends Action
{
    // Affiliation/membership-in-progress guards (blocking a role-flag toggle while
    // organization_counsellors/organization_members rows of that type exist) belong here once
    // TT-6.4a/TT-6.3 introduce those tables -- deliberately not built yet, this ticket only
    // has the org entity itself to guard against.
    public function execute(OrganizationDTO $dto): Organization
    {
        $organization = $dto->organization;

        foreach ([
            'name' => $dto->name,
            'legal_name' => $dto->legalName,
            'registration_number' => $dto->registrationNumber,
            'description' => $dto->description,
            'email' => $dto->email,
            'phone' => $dto->phone,
            'is_provider' => $dto->isProvider,
            'is_consumer' => $dto->isConsumer,
            'self_apply_enabled' => $dto->selfApplyEnabled,
        ] as $column => $value) {
            if (! is_null($value)) {
                $organization->{$column} = $value;
            }
        }

        $organization->save();

        return $organization->refresh();
    }
}
