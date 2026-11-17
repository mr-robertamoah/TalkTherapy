<?php

namespace App\Actions\Counsellor;

use App\Actions\Action;
use App\DTOs\CreateCounsellorDTO;
use App\Exceptions\PotentialUserNotAdultException;

class EnsureUserCanBecomeCounsellorAction extends Action
{
    public function execute(CreateCounsellorDTO $createCounsellorDTO)
    {
        // SCRUM-456: the model's one age calculation (a future date of birth is age 0).
        if ($createCounsellorDTO->potentialCounsellor->isAdult()) {
            return;
        }

        $fullName = $createCounsellorDTO->potentialCounsellor->name ?: 'User';
        throw new PotentialUserNotAdultException("{$fullName} is not an adult and cannot be a counsellor. If this is wrong, then kindly check your date of birth.", 422);
    }
}
