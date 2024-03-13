<?php

namespace App\Actions\Counsellor;
use App\Actions\Action;
use App\DTOs\CreateCounsellorDTO;
use App\Models\Counsellor;

class CreateCounsellorAction extends Action
{
    public function execute(CreateCounsellorDTO $createCounsellorDTO): Counsellor
    {
        return $createCounsellorDTO->potentialCounsellor->counsellor()->create(
            $createCounsellorDTO->getFilledData()
        );
    }
}