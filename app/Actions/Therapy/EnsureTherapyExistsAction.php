<?php

namespace App\Actions\Therapy;
use App\Actions\Action;
use App\DTOs\GetTherapyDTO;
use App\Exceptions\TherapyNotFoundException;

class EnsureTherapyExistsAction extends Action
{
    public function execute(GetTherapyDTO $getTherapyDTO)
    {
        if ($getTherapyDTO->therapy) return;

        throw new TherapyNotFoundException("Therapy was not found.", 422);
    }
}