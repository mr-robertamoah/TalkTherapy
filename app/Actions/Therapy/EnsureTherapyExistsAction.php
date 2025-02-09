<?php

namespace App\Actions\Therapy;

use App\Actions\Action;
use App\DTOs\AssistTherapyDTO;
use App\DTOs\CreateSessionDTO;
use App\DTOs\CreateTherapyDTO;
use App\DTOs\CreateTherapyTopicDTO;
use App\DTOs\GetTherapyDTO;
use App\DTOs\GroupTherapyDTO;
use App\Exceptions\TherapyNotFoundException;

class EnsureTherapyExistsAction extends Action
{
    public function execute(
        GetTherapyDTO|AssistTherapyDTO|CreateTherapyDTO|CreateSessionDTO|CreateTherapyTopicDTO|GroupTherapyDTO $getTherapyDTO,
        $type = 'Therapy'
    )
    {
        $therapy = $type == 'Therapy' ? $getTherapyDTO->therapy : $getTherapyDTO->groupTherapy;

        if ($therapy) return;

        throw new TherapyNotFoundException("{$type} was not found.", 422);
    }
}