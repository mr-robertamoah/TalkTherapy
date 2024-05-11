<?php

namespace App\Actions\Discussion;

use App\Actions\Action;
use App\DTOs\CreateDiscussionDTO;
use App\Exceptions\DiscussionException;

class EnsureDiscussionExistsAction extends Action
{
    public function execute(CreateDiscussionDTO $createDiscussionDTO)
    {
        if ($createDiscussionDTO->discussion) return;

        throw new DiscussionException("Discussion was not found.", 422);
    }
}