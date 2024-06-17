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
        if ($createTherapyTopicDTO->therapyTopic) return;

        throw new TherapyTopicException("Topic was not found.", 422);
    }
}