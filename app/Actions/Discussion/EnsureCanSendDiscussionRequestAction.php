<?php

namespace App\Actions\Discussion;

use App\Actions\Action;
use App\DTOs\CreateRequestDTO;
use App\Exceptions\DiscussionException;
use App\Models\Counsellor;

class EnsureCanSendDiscussionRequestAction extends Action
{
    public function execute(CreateRequestDTO $createRequestDTO)
    {
        // Discussion::isParticipant() is type-hinted ?Counsellor -- guarding explicitly here
        // turns a `from` that's ever not a Counsellor into the same clean 422 instead of an
        // uncaught TypeError, rather than relying on the implicit (currently true, but
        // unenforced) invariant that Discussion::addedby is always a Counsellor.
        if (
            $createRequestDTO->from instanceof Counsellor &&
            $createRequestDTO->for->isParticipant($createRequestDTO->from)
        ) {
            return;
        }

        throw new DiscussionException('You are not authorized to send a request for this discussion.', 422);
    }
}
