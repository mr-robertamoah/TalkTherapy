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
        ) {
            return;
        }

        // SCRUM-344: never names the counsellor -- who counsels a private or anonymous therapy is
        // not the caller's business, even once they're allowed to ask.
        throw new TherapyAlreadyHasAssistanceException('This therapy already has a counsellor.', 422);
    }
}
