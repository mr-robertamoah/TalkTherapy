<?php

namespace App\Actions\Therapy;

use App\Actions\Action;
use App\DTOs\AssistTherapyDTO;
use App\Exceptions\MustBeCounsellorException;

class EnsureIsCounsellorAction extends Action
{
    public function execute(AssistTherapyDTO $assistTherapyDTO)
    {
        if (
            $assistTherapyDTO->user->counsellor
        ) return;

        throw new MustBeCounsellorException("You have to be a counsellor to perform this action.", 422);
    }
}