<?php

namespace App\Actions\Request;

use App\Actions\Action;
use App\DTOs\RequestResponseDTO;
use App\Enums\RequestStatusEnum;
use App\Enums\TherapyStatusEnum;
use App\Models\Counsellor;
use App\Models\Request;

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
                ->whereFor($requestResponseDTO->request->for)
                ->update([
                    'status' => RequestStatusEnum::inconsequencial->value,
                    'data' => [
                        'reason' => 'A similar request for therapy has been accepted by someone else.'
                    ]
                ]);

            // TODO dispatch counsellor to frontend
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
            $requestResponseDTO->request->from::class == Counsellor::class
        ) return $requestResponseDTO->request->from->id;

        return null;
    }
}