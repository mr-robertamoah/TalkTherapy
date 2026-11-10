<?php

namespace App\Actions\TherapyTopic;

use App\Actions\Action;
use App\DTOs\CreateSessionDTO;
use App\DTOs\CreateTherapyTopicDTO;
use App\Exceptions\TherapyTopicException;

class EnsureTherapyTopicExistsAction extends Action
{
    public function execute(CreateTherapyTopicDTO|CreateSessionDTO $createTherapyTopicDTO)
    {
        if ($createTherapyTopicDTO->therapyTopic) {
            return;
        }

        // SCRUM-344: the same answer as a refused topic action, so topic ids can't be probed.
        throw TherapyTopicException::notAllowed();
    }
}
