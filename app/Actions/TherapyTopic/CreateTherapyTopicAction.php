<?php

namespace App\Actions\TherapyTopic;

use App\Actions\Action;
use App\DTOs\CreateTherapyTopicDTO;

class CreateTherapyTopicAction extends Action
{
    public function execute(CreateTherapyTopicDTO $createTherapyTopicDTO)
    {
        $topic = $createTherapyTopicDTO->therapy->topics()->create([
            'name' => $createTherapyTopicDTO->name,
            'description' => $createTherapyTopicDTO->description,
            'counsellor_id' => $createTherapyTopicDTO->user->counsellor->id,
        ]);

        if (
            $createTherapyTopicDTO->sessions && count($createTherapyTopicDTO->sessions)
        ) $topic->sessions()->attach($createTherapyTopicDTO->sessions);

        return $topic;
    }
}