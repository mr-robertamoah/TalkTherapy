<?php

namespace App\Actions\Therapy;

use App\Actions\Action;
use App\DTOs\CreateTherapyDTO;
use App\DTOs\GroupTherapyDTO;
use App\Exceptions\TherapyException;
use App\Models\Therapy;

class EnsureCanUpdateTherapyAction extends Action
{
    public function execute(CreateTherapyDTO|GroupTherapyDTO $dto)
    {
        $therapy = $dto::class == GroupTherapyDTO::class ? $dto->groupTherapy : $dto->therapy;

        // SCRUM-33 (reviews): a quick help chat can't be edited, ended or deleted as a therapy -- its
        // help actions are its only writers (it could otherwise be made public, or end out of step).
        if ($therapy instanceof Therapy && $therapy->isHelpChat()) {
            throw TherapyException::notAllowed();
        }

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
        throw TherapyException::notAllowed();
    }
}
