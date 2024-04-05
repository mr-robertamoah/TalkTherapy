<?php

namespace App\Actions\Therapy;

use App\Actions\Action;
use App\DTOs\AssistTherapyDTO;
use App\DTOs\CreateSessionDTO;
use App\DTOs\CreateTherapyDTO;
use App\DTOs\CreateTherapyTopicDTO;
use App\DTOs\GetTherapyDTO;
use App\Exceptions\TherapyNotFoundException;

class EnsureTherapyExistsAction extends Action
{
    public function execute(
        GetTherapyDTO|AssistTherapyDTO|CreateTherapyDTO|CreateSessionDTO|CreateTherapyTopicDTO $getTherapyDTO
    )
    {
        if ($getTherapyDTO->therapy) return;

        throw new TherapyNotFoundException("Therapy was not found.", 422);
    }
}