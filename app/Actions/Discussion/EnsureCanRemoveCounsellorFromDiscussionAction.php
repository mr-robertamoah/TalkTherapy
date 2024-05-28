<?php

namespace App\Actions\Discussion;

use App\Actions\Action;
use App\DTOs\CreateDiscussionDTO;
use App\DTOs\GetDiscussionsDTO;
use App\Exceptions\DiscussionException;

class EnsureCanRemoveCounsellorFromDiscussionAction extends Action
{
    public function execute(CreateDiscussionDTO|GetDiscussionsDTO $createDiscussionDTO)
    {
        if (
            $createDiscussionDTO->user->isAdmin() ||
            (
                $createDiscussionDTO->discussion->addedby->is($createDiscussionDTO->user) ||
                $createDiscussionDTO->discussion->addedby->is($createDiscussionDTO->user->counsellor)
            )
        ) return;

        throw new DiscussionException("You are not allowed to remove a counsellor from the discussion with name: {$createDiscussionDTO->discussion->name}.", 422);
    }
}