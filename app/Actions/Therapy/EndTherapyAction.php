<?php

namespace App\Actions\Therapy;

use App\Actions\Action;
use App\DTOs\CreateTherapyDTO;
use App\Enums\TherapyStatusEnum;

class EndTherapyAction extends Action
{
    public function execute(CreateTherapyDTO $createTherapyDTO)
    {
        $createTherapyDTO->therapy->update([
            'status' => TherapyStatusEnum::ended->value
        ]);
        
        $createTherapyDTO->therapy->endSessions();
        // TODO dispatch update event
        return $createTherapyDTO->therapy->refresh();
    }
}