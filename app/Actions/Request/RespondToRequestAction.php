<?php

namespace App\Actions\Request;

use App\Actions\Action;
use App\DTOs\RequestResponseDTO;
use App\Enums\RequestTypeEnum;

class RespondToRequestAction extends Action
{
    public function execute(RequestResponseDTO $requestResponseDTO)
    {
        $request = $requestResponseDTO->request;

        if ($requestResponseDTO->request->type == RequestTypeEnum::counsellor->value)
            $request = RespondToCounsellorVerificationRequestAction::new()->execute($requestResponseDTO);

        if ($requestResponseDTO->request->type == RequestTypeEnum::therapy->value)
            $request = RespondToTherapyAssistanceRequestAction::new()->execute($requestResponseDTO);
        
        // TODO respond to other requests
        
        return $request->refresh();
    }
}