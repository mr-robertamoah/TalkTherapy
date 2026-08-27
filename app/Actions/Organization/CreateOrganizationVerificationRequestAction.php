<?php

namespace App\Actions\Organization;

use App\Actions\Action;
use App\Actions\Request\CreateRequestAction;
use App\DTOs\CreateRequestDTO;
use App\Enums\AdministratorTypeEnum;
use App\Enums\RequestTypeEnum;
use App\Models\Organization;
use App\Models\User;

class CreateOrganizationVerificationRequestAction extends Action
{
    // Mirrors CreateCounsellorVerificationRequestAction: submitted to a platform super-admin
    // for approval, distinct from the org<->counsellor/org<->member request flows (SCRUM-120).
    public function execute(Organization $organization)
    {
        return CreateRequestAction::new()->execute(
            CreateRequestDTO::new()->fromArray([
                'for' => $organization,
                'from' => $organization,
                'to' => User::query()->whereHas('administrator', function ($query) {
                    $query->where('type', AdministratorTypeEnum::super->value);
                })->first(),
                'data' => [
                    'registrationNumber' => $organization->registration_number,
                    'legalName' => $organization->legal_name,
                ],
                'type' => RequestTypeEnum::organization->value,
            ])
        );
    }
}
