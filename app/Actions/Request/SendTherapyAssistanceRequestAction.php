<?php

namespace App\Actions\Request;

use App\Actions\Action;
use App\DTOs\CreateRequestDTO;
use App\DTOs\TherapyAssistanceRequestDTO;
use App\Enums\RequestTypeEnum;
use App\Notifications\TherapyAssistanceRequestSentNotification;
use App\Services\RequestService;

class SendTherapyAssistanceRequestAction extends Action
{
    public function execute(TherapyAssistanceRequestDTO $therapyAssistanceRequestDTO)
    {
        ds($therapyAssistanceRequestDTO);
        if (
            is_null($therapyAssistanceRequestDTO->from) ||
            is_null($therapyAssistanceRequestDTO->for) ||
            is_null($therapyAssistanceRequestDTO->to)
        ) return null;

        $request = RequestService::new()->createRequest(
            CreateRequestDTO::new()->fromArray([
                'from' => $therapyAssistanceRequestDTO->from,
                'to' => $therapyAssistanceRequestDTO->to,
                'for' => $therapyAssistanceRequestDTO->for,
                'type' => RequestTypeEnum::therapy->value,
            ])
        );

        $therapyAssistanceRequestDTO->to->notify(new TherapyAssistanceRequestSentNotification($request));
        return $request;
    }
}