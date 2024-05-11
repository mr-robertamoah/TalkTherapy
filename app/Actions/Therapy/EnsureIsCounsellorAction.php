<?php

namespace App\Actions\Therapy;

use App\Actions\Action;
use App\DTOs\AssistTherapyDTO;
use App\DTOs\CreateDiscussionDTO;
use App\Exceptions\MustBeCounsellorException;

class EnsureIsCounsellorAction extends Action
{
    public function execute(AssistTherapyDTO|CreateDiscussionDTO $assistTherapyDTO)
    {
        if (
            $assistTherapyDTO->user->isAdmin() ||
            $assistTherapyDTO->user->counsellor
        ) return;

        throw new MustBeCounsellorException("You have to be a counsellor to perform this action.", 422);
    }
}