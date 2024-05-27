<?php

namespace App\Actions\User;

use App\Actions\Action;
use App\Actions\Request\CreateRequestAction;
use App\DTOs\CreateRequestDTO;
use App\Enums\RequestTypeEnum;
use App\Notifications\GuardianshipRequestNotification;

class CreateGuardianshipRequestAction extends Action
{
    public function execute(CreateRequestDTO $createRequestDTO)
    {
        $createRequestDTO = $createRequestDTO->withType(RequestTypeEnum::guardianship->value);
        
        $request = CreateRequestAction::new()->execute($createRequestDTO);

        $request->to->notify(new GuardianshipRequestNotification($createRequestDTO->from));

        return $request->refresh();
    }
}