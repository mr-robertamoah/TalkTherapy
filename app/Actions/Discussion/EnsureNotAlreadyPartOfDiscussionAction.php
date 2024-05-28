<?php

namespace App\Actions\Discussion;

use App\Actions\Action;
use App\DTOs\CreateRequestDTO;
use App\Exceptions\DiscussionException;

class EnsureNotAlreadyPartOfDiscussionAction extends Action
{
    public function execute(CreateRequestDTO $createRequestDTO)
    {
        if ($createRequestDTO->for->isNotParticipant($createRequestDTO->to)) return;

        throw new DiscussionException("The counsellor you are trying to send a request is alreaady part of the discussion.", 422);
    }
}