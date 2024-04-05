<?php

namespace App\Actions\Therapy;

use App\Actions\Action;
use App\DTOs\AssistTherapyDTO;
use App\Exceptions\TherapyAlreadyHasAssistanceException;

class EnsureTherapyHasNoAssistanceAction extends Action
{
    public function execute(AssistTherapyDTO $assistTherapyDTO)
    {
        if (
            $assistTherapyDTO->therapy->doesNotHaveAssistance()
        ) return;

        throw new TherapyAlreadyHasAssistanceException("Therapy already has the assistance of {$assistTherapyDTO->therapy->counsellor->getName()}.", 422);
    }
}