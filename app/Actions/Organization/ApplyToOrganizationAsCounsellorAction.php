<?php

namespace App\Actions\Organization;

use App\Actions\Action;
use App\Actions\Request\CreateRequestAction;
use App\DTOs\CreateRequestDTO;
use App\DTOs\OrganizationCounsellorRequestDTO;
use App\Enums\RequestTypeEnum;

class ApplyToOrganizationAsCounsellorAction extends Action
{
    public function execute(OrganizationCounsellorRequestDTO $dto)
    {
        return CreateRequestAction::new()->execute(
            CreateRequestDTO::new()->fromArray([
                'for' => $dto->organization,
                'from' => $dto->counsellor,
                'to' => $dto->organization,
                'type' => RequestTypeEnum::organizationCounsellorApplication->value,
            ])
        );
    }
}
