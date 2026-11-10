<?php

namespace App\Actions\Discussion;

use App\Actions\Action;
use App\DTOs\CreateDiscussionDTO;
use App\DTOs\GetDiscussionsDTO;
use App\Exceptions\DiscussionException;

class EnsureDiscussionExistsAction extends Action
{
    public function execute(CreateDiscussionDTO|GetDiscussionsDTO $createDiscussionDTO)
    {
        if ($createDiscussionDTO->discussion) {
            return;
        }

        // SCRUM-343: see DiscussionException::notAllowed() -- otherwise the difference reveals which
        // discussion ids exist.
        throw DiscussionException::notAllowed();
    }
}
