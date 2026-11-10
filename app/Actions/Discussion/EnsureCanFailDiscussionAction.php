<?php

namespace App\Actions\Discussion;

use App\Actions\Action;
use App\DTOs\CreateDiscussionDTO;
use App\Exceptions\DiscussionException;

class EnsureCanFailDiscussionAction extends Action
{
    public function execute(CreateDiscussionDTO $createDiscussionDTO)
    {
        // SCRUM-343: authorize before the state check below, which names the discussion.
        if (! $createDiscussionDTO->discussion->isManageableBy($createDiscussionDTO->user)) {
            throw DiscussionException::notAllowed();
        }

        if (! $createDiscussionDTO->user->isAdmin() && ! now()->lessThan($createDiscussionDTO->discussion->end_time)) {
            throw new DiscussionException('You can only mark this discussion as failed before its end time.', 422);
        }
    }
}
