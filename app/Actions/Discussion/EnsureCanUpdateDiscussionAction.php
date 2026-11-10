<?php

namespace App\Actions\Discussion;

use App\Actions\Action;
use App\DTOs\CreateDiscussionDTO;
use App\DTOs\GetDiscussionsDTO;
use App\Exceptions\DiscussionException;

class EnsureCanUpdateDiscussionAction extends Action
{
    public function execute(CreateDiscussionDTO|GetDiscussionsDTO $createDiscussionDTO)
    {
        // SCRUM-343: authorize before the state check below, which names the discussion.
        if (! $createDiscussionDTO->discussion->isManageableBy($createDiscussionDTO->user)) {
            throw DiscussionException::notAllowed();
        }

        if ($createDiscussionDTO->discussion->isNotUpdateable()) {
            throw new DiscussionException("'{$createDiscussionDTO->discussion->name}' discussion cannot be updated because it is either in session or has ended.", 422);
        }
    }
}
