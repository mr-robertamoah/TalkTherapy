<?php

namespace App\Actions\Therapy;

use App\Actions\Action;
use App\DTOs\AssistTherapyDTO;
use App\DTOs\CreateSessionDTO;
use App\DTOs\CreateTherapyDTO;
use App\DTOs\CreateTherapyTopicDTO;
use App\DTOs\GetTherapyDTO;
use App\DTOs\GroupTherapyDTO;
use App\DTOs\JoinGroupTherapyDTO;
use App\DTOs\SessionScheduleProposalDTO;
use App\Exceptions\TherapyException;
use App\Exceptions\TherapyNotFoundException;

class EnsureTherapyExistsAction extends Action
{
    public function execute(
        GetTherapyDTO|AssistTherapyDTO|CreateTherapyDTO|CreateSessionDTO|CreateTherapyTopicDTO|GroupTherapyDTO|JoinGroupTherapyDTO|SessionScheduleProposalDTO $getTherapyDTO,
        $type = 'Therapy'
    ) {
        $therapy = $type == 'Therapy' ? $getTherapyDTO->therapy : $getTherapyDTO->groupTherapy;

        if ($therapy) {
            return;
        }

        // SCRUM-343: the same answer EnsureCanUpdateTherapyAction gives a caller it refuses --
        // otherwise the difference reveals which therapy ids exist.
        throw new TherapyNotFoundException(TherapyException::NOT_ALLOWED, 422);
    }
}
