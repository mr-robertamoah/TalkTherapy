<?php

namespace App\Actions\Therapy;

use App\Actions\Action;
use App\DTOs\CreateTherapyDTO;

class DeleteTherapyAction extends Action
{
    public function execute(CreateTherapyDTO $createTherapyDTO)
    {
        $createTherapyDTO->therapy->endSessions();

        $createTherapyDTO->therapy->starreable()->delete();

        $createTherapyDTO->therapy->delete();

        // TODO dispatch event to frontend

        return $createTherapyDTO->therapy->refresh();
    }
}