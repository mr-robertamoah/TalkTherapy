<?php

namespace App\Actions\Discussion;

use App\Actions\Action;
use App\DTOs\CreateDiscussionDTO;
use App\Exceptions\DiscussionException;

class EnsureCanUpdateDiscussionStatusAction extends Action
{
    public function execute(CreateDiscussionDTO $createDiscussionDTO, string $type = 'start')
    {
        if (
            $createDiscussionDTO->user->isAdmin() ||
            $createDiscussionDTO->discussion->addedby->is($createDiscussionDTO->user) ||
            $createDiscussionDTO->discussion->addedby->is($createDiscussionDTO->user->counsellor)
        ) return;

        throw new DiscussionException("You cannot {$type} discussion with name: {$createDiscussionDTO->discussion->name}.", 422);
    }
}