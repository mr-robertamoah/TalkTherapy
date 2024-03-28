<?php

namespace App\Actions\Therapy;
use App\Actions\Action;
use App\DTOs\GetTherapyDTO;
use App\Exceptions\TherapyAccessDeniedException;

class EnsureUserHasAccessToTherapyAction extends Action
{

    public function execute(GetTherapyDTO $getTherapyDTO)
    {
        if (
            $getTherapyDTO->therapy->public ||
            $getTherapyDTO->therapy->isParticipant($getTherapyDTO->user) ||
            $getTherapyDTO->user->isAdmin()
        ) return;

        throw new TherapyAccessDeniedException("You are not allowed to assess therapy with id: {$getTherapyDTO->therapy->id}", 422);
    }
}