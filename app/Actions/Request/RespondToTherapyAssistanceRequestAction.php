<?php

namespace App\Actions\Request;

use App\Actions\Action;
use App\Actions\User\AlertGuardianAction;
use App\DTOs\GuardianAlertDTO;
use App\DTOs\RequestResponseDTO;
use App\Enums\RequestStatusEnum;
use App\Enums\TherapyStatusEnum;
use App\Models\Counsellor;
use App\Models\Request;
use App\Notifications\TherapyAssistanceRequestAcceptedGuardianNotification;
use App\Notifications\TherapyAssistanceRequestAcceptedNotification;

class RespondToTherapyAssistanceRequestAction extends Action
{
    public function execute(RequestResponseDTO $requestResponseDTO)
    {
        // TODO counsellor must have a certain number of free therapies
        $response = is_null($requestResponseDTO->response)
            ? RequestStatusEnum::rejected->value
            : strtoupper($requestResponseDTO->response);

        if ($requestResponseDTO->request->for->counsellor)
            $response = RequestStatusEnum::rejected->value;

        $requestResponseDTO->request->update([
            'status' => $response
        ]);

        if ($response == RequestStatusEnum::accepted->value) {
            $requestResponseDTO->request->for->update([
                'counsellor_id' => $this->getCounsellorId($requestResponseDTO),
                'status' => TherapyStatusEnum::in_session->value
            ]);

            Request::query()
                ->whereNot('id', $requestResponseDTO->request->id)
                ->wherePending()
                ->whereFor($requestResponseDTO->request->for)
                ->update([
                    'status' => RequestStatusEnum::inconsequencial->value,
                    'data' => [
                        'reason' => 'A similar request for therapy assistance has been accepted by someone else.'
                    ]
                ]);

            // TODO dispatch counsellor to frontend
            $requestResponseDTO->request->from->notify(
                new TherapyAssistanceRequestAcceptedNotification($requestResponseDTO->request)
            );

            AlertGuardianAction::new()->execute(
                GuardianAlertDTO::new()->fromArray([
                    'user' => $requestResponseDTO->request->from::class == Counsellor::class
                        ? $requestResponseDTO->request->from->user
                        : $requestResponseDTO->request->from,
                    'notification' => new TherapyAssistanceRequestAcceptedGuardianNotification(
                        $requestResponseDTO->request->for
                    )
                ])
            );
        }
        
        return $requestResponseDTO->request->refresh();
    }

    private function getCounsellorId(RequestResponseDTO $requestResponseDTO)
    {
        if (
            $requestResponseDTO->user->counsellor &&
            $requestResponseDTO->user->counsellor->is($requestResponseDTO->request->to)
        ) return $requestResponseDTO->user->counsellor->id;
        
        if (
            $requestResponseDTO->request->to->is($requestResponseDTO->user) &&
            $requestResponseDTO->request->from_type == Counsellor::class
        ) return $requestResponseDTO->request->from->id;

        return null;
    }
}