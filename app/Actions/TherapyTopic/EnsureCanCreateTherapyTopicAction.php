<?php

namespace App\Actions\TherapyTopic;

use App\Actions\Action;
use App\DTOs\CreateTherapyTopicDTO;
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
        ) return;

        throw new TherapyTopicException("You are not allowed to create a topic for therapy with name: {$createTherapyTopicDTO->therapy->name}.", 422);
    }
}