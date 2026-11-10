<?php

namespace App\Actions\TherapyTopic;

use App\Actions\Action;
use App\DTOs\CreateTherapyTopicDTO;
use App\Exceptions\TherapyException;
use App\Exceptions\TherapyTopicException;

class EnsureCanCreateTherapyTopicAction extends Action
{
    public function execute(CreateTherapyTopicDTO $createTherapyTopicDTO)
    {
        if (
            $createTherapyTopicDTO->user->isAdmin() ||
            (
                $createTherapyTopicDTO->user->counsellor &&
                $createTherapyTopicDTO->therapy->isCounsellor($createTherapyTopicDTO->user->counsellor)
            )
            // TODO not banned from creating or suspending from the app
        ) {
            return;
        }

        // SCRUM-344: the same answer as a missing therapy (EnsureTherapyExistsAction), and never its
        // name -- any signed-in user can reach this.
        throw new TherapyTopicException(TherapyException::NOT_ALLOWED, 422);
    }
}
