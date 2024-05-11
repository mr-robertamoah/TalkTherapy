<?php

namespace App\Actions\Discussion;

use App\Actions\Action;
use App\DTOs\CreateDiscussionDTO;
use App\Exceptions\DiscussionException;

class EnsureCanEndDiscussionAction extends Action
{
    public function execute(CreateDiscussionDTO $createDiscussionDTO)
    {
        if (
            $createDiscussionDTO->user->isAdmin() ||
            (
                now()->greaterThan($createDiscussionDTO->discussion->end_time) &&
                (
                    $createDiscussionDTO->discussion->addedby->is($createDiscussionDTO->user) ||
                    $createDiscussionDTO->discussion->addedby->is($createDiscussionDTO->user->counsellor)
                )
            )
        ) return;

        throw new DiscussionException("You are not allowed to end discussion with name: {$createDiscussionDTO->discussion->name}. Also ensure that it is past the end time of the session.", 422);
    }
}