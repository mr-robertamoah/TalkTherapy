<?php

namespace App\Actions\TherapyTopic;

use App\Actions\Action;
use App\DTOs\CreateTherapyTopicDTO;
use App\Exceptions\TherapyTopicException;

class EnsureTherapyTopicExistsAction extends Action
{
    public function execute(CreateTherapyTopicDTO $createTherapyTopicDTO)
    {
        if ($createTherapyTopicDTO->therapyTopic) return;

        throw new TherapyTopicException("Topic was not found.", 422);
    }
}