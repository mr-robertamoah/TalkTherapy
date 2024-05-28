<?php

namespace App\Actions\Discussion;

use App\Actions\Action;
use App\Actions\Request\CreateRequestAction;
use App\DTOs\CreateRequestDTO;
use App\Enums\RequestTypeEnum;
use App\Notifications\DiscussionRequestNotification;

class CreateDiscussionRequestAction extends Action
{
    public function execute(CreateRequestDTO $createRequestDTO)
    {
        $createRequestDTO = $createRequestDTO->withType(RequestTypeEnum::discussion->value);
        
        $request = CreateRequestAction::new()->execute($createRequestDTO);

        $request->to->notify(new DiscussionRequestNotification($createRequestDTO->for));

        return $request->refresh();
    }
}