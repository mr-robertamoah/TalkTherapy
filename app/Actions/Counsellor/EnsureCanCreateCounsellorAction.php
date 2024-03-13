<?php

namespace App\Actions\Counsellor;

use App\Actions\Action;
use App\DTOs\CreateCounsellorDTO;
use App\Exceptions\BadRequestException;

class EnsureCanCreateCounsellorAction extends Action
{
    public function execute(CreateCounsellorDTO $createCounsellorDTO)
    {
        if (
            $createCounsellorDTO->user->isAdmin() ||
            $createCounsellorDTO->user->is($createCounsellorDTO->potentialCounsellor)
        ) return;

        throw new BadRequestException('You are not allowed to make this user a counsellor.', 422);
    }
}