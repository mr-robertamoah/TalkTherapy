<?php

namespace App\Actions\User;

use App\Actions\Action;
use App\DTOs\CreateRequestDTO;
use App\Enums\RequestTypeEnum;
use App\Exceptions\UserException;

class EnsureUserDoesNotHaveAGuardianshipRequestAction extends Action
{
    public function execute(CreateRequestDTO $createRequestDTO)
    {
        if (
            !$createRequestDTO->from
                ->sentRequests()
                ->wherePending()
                ->whereTo($createRequestDTO->to)
                ->whereType(RequestTypeEnum::guardianship->value)
                ->exists()
        ) return;

        throw new UserException("The user you are trying to send a guardianship request already has a pending guardianship request from you.", 422);
    }
}