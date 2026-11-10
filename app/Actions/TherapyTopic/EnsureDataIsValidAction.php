<?php

namespace App\Actions\TherapyTopic;

use App\Actions\Action;
use App\DTOs\CreateTherapyTopicDTO;
use App\Exceptions\TherapyTopicException;

class EnsureDataIsValidAction extends Action
{
    public function execute(CreateTherapyTopicDTO $createTherapyTopicDTO)
    {
        // SCRUM-344: on update, check against the topic's own owner -- a Therapy or a GroupTherapy
        // (the controller can only pass a Therapy as `therapy`, so group topics used to crash here).
        $owner = $createTherapyTopicDTO->therapyTopic?->topicable ?? $createTherapyTopicDTO->therapy;

        $sessionsCount = $owner
            ->sessions()
            ->whereIn('id', $createTherapyTopicDTO->sessions ?: [])
            ->count();

        if (
            ! $createTherapyTopicDTO->sessions ||
            ! count($createTherapyTopicDTO->sessions) ||
            $sessionsCount == count($createTherapyTopicDTO->sessions)
        ) {
            return;
        }

        throw new TherapyTopicException('Some of the selected sessions are not valid.', 422);
    }
}
