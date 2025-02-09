<?php

namespace App\Actions\Request;

use App\Actions\Action;
use App\DTOs\CreateRequestDTO;
use App\DTOs\TherapyAssistanceRequestDTO;
use App\Enums\RequestTypeEnum;
use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Notifications\GroupTherapyAssistanceRequestSentNotification;
use App\Notifications\TherapyAssistanceRequestSentNotification;
use App\Services\RequestService;

class SendTherapyAssistanceRequestAction extends Action
{
    public function execute(TherapyAssistanceRequestDTO $therapyAssistanceRequestDTO)
    {
        if (
            is_null($therapyAssistanceRequestDTO->from) ||
            is_null($therapyAssistanceRequestDTO->for) ||
            is_null($therapyAssistanceRequestDTO->to)
        ) return null;

        if (
            $therapyAssistanceRequestDTO->for::class == GroupTherapy::class
        ) return $this->sendMultipleRequests($therapyAssistanceRequestDTO);

        $request = CreateRequestAction::new()->execute(
            CreateRequestDTO::new()->fromArray([
                'from' => $therapyAssistanceRequestDTO->from,
                'to' => $therapyAssistanceRequestDTO->to,
                'for' => $therapyAssistanceRequestDTO->for,
                'type' => RequestTypeEnum::therapy->value,
                'data' => $therapyAssistanceRequestDTO->for->payment_data,
            ])
        );

        $therapyAssistanceRequestDTO->to->notify(
            new TherapyAssistanceRequestSentNotification($request)
        );
        
        return $request;
    }

    private function sendMultipleRequests(TherapyAssistanceRequestDTO $therapyAssistanceRequestDTO)
    {
        $requests = [];

        foreach ($therapyAssistanceRequestDTO->to as $key => $value) {
            $counsellor = Counsellor::find($value);
    
            if (!$counsellor) return;

            $request = CreateRequestAction::new()->execute(
                CreateRequestDTO::new()->fromArray([
                    'from' => $therapyAssistanceRequestDTO->from,
                    'to' => $counsellor,
                    'for' => $therapyAssistanceRequestDTO->for,
                    'type' => RequestTypeEnum::groupTherapy->value,
                    'data' => $therapyAssistanceRequestDTO->for->payment_data,
                ])
            );
    
            $request->to->notify(
                new GroupTherapyAssistanceRequestSentNotification($request)
            );
            
            $requests[] = $request;
        }

        return $requests;
    }
}