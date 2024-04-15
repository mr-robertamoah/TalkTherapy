<?php

namespace App\Actions\TherapyTopic;

use App\Actions\Action;
use App\DTOs\CreateTherapyTopicDTO;
use App\Exceptions\TherapyTopicException;

class EnsureCanUpdateTherapyTopicAction extends Action
{
    public function execute(CreateTherapyTopicDTO $createTherapyTopicDTO)
    {
        if (
            $createTherapyTopicDTO->user->isAdmin() ||
            $createTherapyTopicDTO->therapyTopic->counsellor->user->is($createTherapyTopicDTO->user)
        ) return;

        throw new TherapyTopicException("You are not authorized to update the topic with name: {$createTherapyTopicDTO->therapyTopic->name}.", 422);
    }
}