<?php

namespace App\Actions\Organization;

use App\Actions\Action;
use App\Actions\Request\CreateRequestAction;
use App\DTOs\CreateRequestDTO;
use App\DTOs\OrganizationCounsellorRequestDTO;
use App\Enums\RequestTypeEnum;

class InviteCounsellorToOrganizationAction extends Action
{
    public function execute(OrganizationCounsellorRequestDTO $dto)
    {
        return CreateRequestAction::new()->execute(
            CreateRequestDTO::new()->fromArray([
                'for' => $dto->organization,
                'from' => $dto->organization,
                'to' => $dto->counsellor,
                'type' => RequestTypeEnum::organizationCounsellorInvite->value,
            ])
        );
    }
}
