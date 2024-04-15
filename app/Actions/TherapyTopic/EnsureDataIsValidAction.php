<?php

namespace App\Actions\TherapyTopic;

use App\Actions\Action;
use App\DTOs\CreateTherapyTopicDTO;
use App\Exceptions\TherapyTopicException;

class EnsureDataIsValidAction extends Action
{
    public function execute(CreateTherapyTopicDTO $createTherapyTopicDTO)
    {
        $sessionsCount = $createTherapyTopicDTO->therapy
            ->sessions()
            ->whereIn('id', $createTherapyTopicDTO->sessions ?: [])
            ->count();

        if (
            !$createTherapyTopicDTO->sessions ||
            !count($createTherapyTopicDTO->sessions) ||
            $sessionsCount == count($createTherapyTopicDTO->sessions)
        ) return;

        throw new TherapyTopicException("Some of the selected sessions are not valid.", 422);
    }
}