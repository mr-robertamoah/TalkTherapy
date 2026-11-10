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
        if (! $createDiscussionDTO->discussion->isManageableBy($createDiscussionDTO->user)) {
            throw DiscussionException::notAllowed();
        }
    }
}
