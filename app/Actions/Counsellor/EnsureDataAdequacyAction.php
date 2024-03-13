<?php

namespace App\Actions\Counsellor;

use App\Actions\Action;
use App\DTOs\CreateCounsellorDTO;
use App\Exceptions\BadRequestException;

class EnsureDataAdequacyAction extends Action
{
    public function execute(CreateCounsellorDTO $createCounsellorDTO): CreateCounsellorDTO
    {
        if (!$createCounsellorDTO->user && !$createCounsellorDTO->potentialCounsellor) {
            throw new BadRequestException('No users were provided.', 422);
        }

        if ($createCounsellorDTO->user && !$createCounsellorDTO->potentialCounsellor) {
            $createCounsellorDTO = $createCounsellorDTO->withPotentialCounsellor($createCounsellorDTO->user);
        }

        return $createCounsellorDTO;
    }
}