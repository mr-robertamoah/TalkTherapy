<?php

namespace App\Actions\Organization;

use App\Actions\Action;
use App\Actions\Request\CreateRequestAction;
use App\DTOs\CreateRequestDTO;
use App\DTOs\OrganizationMemberRequestDTO;
use App\Enums\RequestTypeEnum;

class InviteMemberToOrganizationAction extends Action
{
    public function execute(OrganizationMemberRequestDTO $dto)
    {
        return CreateRequestAction::new()->execute(
            CreateRequestDTO::new()->fromArray([
                'for' => $dto->organization,
                'from' => $dto->organization,
                'to' => $dto->member,
                'type' => RequestTypeEnum::organizationMemberInvite->value,
            ])
        );
    }
}
