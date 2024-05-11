<?php

namespace App\Actions\Discussion;

use App\Actions\Action;
use App\DTOs\CreateDiscussionDTO;

class DeleteDiscussionAction extends Action
{
    public function execute(CreateDiscussionDTO $createDiscussionDTO)
    {
        $createDiscussionDTO->discussion->starreable()->delete();

        $createDiscussionDTO->discussion->delete();

        return $createDiscussionDTO->discussion->refresh();
    }
}