<?php

namespace App\Actions\Counsellor;

use App\Actions\Action;
use App\DTOs\CreateCounsellorDTO;
use App\Exceptions\PotentialUserNotAdultException;

class EnsureUserCanBecomeCounsellorAction extends Action
{
    public function execute(CreateCounsellorDTO $createCounsellorDTO)
    {
        $age = $createCounsellorDTO->potentialCounsellor->dob
            ? $createCounsellorDTO->potentialCounsellor->dob->diffInYears()
            : 0 ;
        if ($age >= 18) return;

        $fullName = $createCounsellorDTO->potentialCounsellor->name ?: 'User';
        throw new PotentialUserNotAdultException("{$fullName} is not an adult and cannot be a counsellor. If this is wrong, then kindly check your date of birth.", 422);
    }
}