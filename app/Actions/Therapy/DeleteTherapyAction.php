<?php

namespace App\Actions\Therapy;

use App\Actions\Action;
use App\DTOs\CreateTherapyDTO;
use App\DTOs\GroupTherapyDTO;

class DeleteTherapyAction extends Action
{
    public function execute(CreateTherapyDTO|GroupTherapyDTO $dto)
    {
        $therapy = $dto::class == GroupTherapyDTO::class ? $dto->groupTherapy : $dto->therapy;

        $therapy->endSessions();

        $therapy->starreable()->delete();

        $therapy->delete();

        // TODO dispatch event to frontend

        return $therapy->refresh();
    }
}