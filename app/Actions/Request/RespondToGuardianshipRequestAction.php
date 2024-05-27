<?php

namespace App\Actions\Request;

use App\Actions\Action;
use App\Actions\User\EnsureUserCanBeGuardianAction;
use App\DTOs\CreateRequestDTO;
use App\DTOs\RequestResponseDTO;
use App\Enums\RequestStatusEnum;
use App\Models\License;
use App\Notifications\GuardianshipEstablishedNotification;

class RespondToGuardianshipRequestAction extends Action
{
    public function execute(RequestResponseDTO $requestResponseDTO)
    {
        EnsureUserCanBeGuardianAction::new()->execute(
            CreateRequestDTO::new()->fromArray(['to' => $requestResponseDTO->request->to]),
            "You are trying to respond to a guardianship request but do not qualify to be a guardian because you are not an adult, have not set date or birth, have not set email or have not verified your email."
        );

        $requestResponseDTO->request->update([
            'status' => is_null($requestResponseDTO->response)
                ? RequestStatusEnum::rejected->value
                : strtoupper($requestResponseDTO->response)
        ]);
        
        $request = $requestResponseDTO->request->refresh();

        if ($request->status == RequestStatusEnum::accepted->value) {
            $request->from->guardians()->create(['guardian_id' => $request->to->id]);
            $request->from->notify(
                new GuardianshipEstablishedNotification($request->to)
            );
        }
        
        return $request;
    }
}