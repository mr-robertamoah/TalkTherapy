<?php

namespace App\Actions\Discussion;

use App\Actions\Action;
use App\DTOs\CreateDiscussionDTO;
use App\Exceptions\DiscussionException;

class EnsureCanUpdateDiscussionAction extends Action
{
    public function execute(CreateDiscussionDTO $createDiscussionDTO)
    {
        if ($createDiscussionDTO->discussion->isNotUpdateable())
            throw new DiscussionException("'{$createDiscussionDTO->discussion->name}' discussion cannot be updated because it is either in session or has ended.", 422);

        if (
            $createDiscussionDTO->user->isAdmin() ||
            (
                $createDiscussionDTO->discussion->addedby->is($createDiscussionDTO->user) ||
                $createDiscussionDTO->discussion->addedby->is($createDiscussionDTO->user->counsellor)
            )
        ) return;

        throw new DiscussionException("You are not allowed to update discussion with name: {$createDiscussionDTO->discussion->name}.", 422);
    }
}