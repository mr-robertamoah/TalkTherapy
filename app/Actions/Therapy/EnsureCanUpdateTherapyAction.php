<?php

namespace App\Actions\Therapy;

use App\Actions\Action;
use App\DTOs\CreateTherapyDTO;
use App\DTOs\GroupTherapyDTO;
use App\Exceptions\TherapyException;

class EnsureCanUpdateTherapyAction extends Action
{
    public function execute(CreateTherapyDTO|GroupTherapyDTO $dto)
    {
        $therapy = $dto::class == GroupTherapyDTO::class ? $dto->groupTherapy : $dto->therapy;

        if (
            $dto->user->isAdmin() ||
            $dto->user->is($therapy->addedby) ||
            (
                $dto->user->counsellor &&
                $dto->user->counsellor->is($therapy->addedby)
            )
        ) {
            return;
        }

        // SCRUM-343: never names the therapy -- this refusal reaches people with no access to it.
        throw new TherapyException('You are not allowed to do that with this therapy.', 422);
    }
}
