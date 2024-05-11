<?php

namespace App\Actions\Discussion;

use App\Actions\Action;
use App\DTOs\CreateDiscussionDTO;
use App\Enums\DiscussionTypeEnum;
use App\Exceptions\DiscussionException;

class EnsureCanDeleteDiscussionAction extends Action
{
    public function execute(CreateDiscussionDTO $createDiscussionDTO)
    {
        if ($createDiscussionDTO->discussion->isNotDeleteable())
            throw new DiscussionException("{$createDiscussionDTO->discussion->name} discussion cannot be deleted because it is either about to start or has started.", 422);

        if (
            (
                $createDiscussionDTO->user->isAdmin() ||
                (
                    $createDiscussionDTO->discussion->addedby->is($createDiscussionDTO->user) ||
                    $createDiscussionDTO->discussion->addedby->is($createDiscussionDTO->user->counsellor)
                )
            )
        ) return;

        throw new DiscussionException("You are not allowed to delete discussion with name: {$createDiscussionDTO->discussion->name}.", 422);
    }
}