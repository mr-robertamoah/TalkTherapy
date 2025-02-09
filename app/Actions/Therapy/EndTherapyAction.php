<?php

namespace App\Actions\Therapy;

use App\Actions\Action;
use App\DTOs\CreateTherapyDTO;
use App\DTOs\GroupTherapyDTO;
use App\Enums\TherapyStatusEnum;

class EndTherapyAction extends Action
{
    public function execute(CreateTherapyDTO|GroupTherapyDTO $dto)
    {
        $therapy = $dto::class == GroupTherapyDTO::class ? $dto->groupTherapy : $dto->therapy;

        $therapy->update([
            'status' => TherapyStatusEnum::ended->value
        ]);
        
        $therapy->endSessions();

        // TODO dispatch update event
        return $therapy->refresh();
    }
}