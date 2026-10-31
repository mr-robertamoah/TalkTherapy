<?php

namespace App\Actions\Discussion;

use App\Actions\Action;
use App\DTOs\CreateDiscussionDTO;
use App\Exceptions\DiscussionException;

class EnsureCanDeleteDiscussionAction extends Action
{
    public function execute(CreateDiscussionDTO $createDiscussionDTO)
    {
        // SCRUM-343: authorize before the state check below, which names the discussion.
        if (! $createDiscussionDTO->discussion->isManageableBy($createDiscussionDTO->user)) {
            throw DiscussionException::notAllowed();
        }

        if ($createDiscussionDTO->discussion->isNotDeleteable()) {
            throw new DiscussionException("{$createDiscussionDTO->discussion->name} discussion cannot be deleted because it is either about to start or has started.", 422);
        }
    }
}
