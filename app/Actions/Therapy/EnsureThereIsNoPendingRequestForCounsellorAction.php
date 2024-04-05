<?php

namespace App\Actions\Therapy;

use App\Actions\Action;
use App\DTOs\AssistTherapyDTO;
use App\Exceptions\TherapyException;

class EnsureThereIsNoPendingRequestForCounsellorAction extends Action
{
    public function execute(AssistTherapyDTO $assistTherapyDTO)
    {
        if (
            $assistTherapyDTO->user->counsellor->doesNotHavePendingRequestFor($assistTherapyDTO->therapy)
        ) return;

        throw new TherapyException("You already have a pending request for this same therapy. Visit requests and respond to request.", 422);
    }
}