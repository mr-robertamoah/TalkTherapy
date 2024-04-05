<?php

namespace App\Actions\Session;

use App\Actions\Action;
use App\DTOs\CreateTherapyTopicDTO;

class DeleteTherapyTopicAction extends Action
{
    public function execute(CreateTherapyTopicDTO $createTherapyTopicDTO)
    {
        $createTherapyTopicDTO->therapyTopic->starreable()->delete();

        $createTherapyTopicDTO->therapyTopic->delete();

        // TODO dispatch event to frontend

        return $createTherapyTopicDTO->therapyTopic->refresh();
    }
}