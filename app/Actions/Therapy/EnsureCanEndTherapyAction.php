<?php

namespace App\Actions\Therapy;

use App\Actions\Action;
use App\DTOs\CreateTherapyDTO;
use App\DTOs\GroupTherapyDTO;
use App\Enums\TherapySessionTypeEnum;
use App\Exceptions\TherapyException;

class EnsureCanEndTherapyAction extends Action
{
    public function execute(CreateTherapyDTO|GroupTherapyDTO $dto)
    {
        $therapy = $dto::class == GroupTherapyDTO::class ? $dto->groupTherapy : $dto->therapy;
        $sessionsHeld = $therapy->sessionsHeld;

        if (
            $dto->user->isAdmin() ||
            ($sessionsHeld && $therapy->session_type == TherapySessionTypeEnum::periodic->value)
        ) return;

        $type = $dto::class == GroupTherapyDTO::class ? 'group' : $dto->therapy;
        throw new TherapyException("You are not allowed to end {$type} with name: {$therapy->name}. You are either not authorized or there are less than the required held sessions.", 422);
    }
}