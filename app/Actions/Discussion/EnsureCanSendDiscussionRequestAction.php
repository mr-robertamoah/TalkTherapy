<?php

namespace App\Actions\Discussion;

use App\Actions\Action;
use App\DTOs\CreateRequestDTO;
use App\Exceptions\DiscussionException;
use App\Models\Counsellor;
use App\Models\Discussion;

class EnsureCanSendDiscussionRequestAction extends Action
{
    public function execute(CreateRequestDTO $createRequestDTO)
    {
        // Discussion::isParticipant() is type-hinted ?Counsellor -- guarding explicitly here
        // turns a `from` that's ever not a Counsellor into the same clean 422 instead of an
        // uncaught TypeError, rather than relying on the implicit (currently true, but
        // unenforced) invariant that Discussion::addedby is always a Counsellor.
        //
        // SCRUM-344: also the check for a missing discussion, with the same refusal as every other
        // discussion action -- it used to fall through to a mismatched "not enough data for a
        // guardianship request" message, which told callers which discussion ids exist.
        if (
            $createRequestDTO->for instanceof Discussion &&
            $createRequestDTO->from instanceof Counsellor &&
            $createRequestDTO->for->isParticipant($createRequestDTO->from)
        ) {
            return;
        }

        throw DiscussionException::notAllowed();
    }
}
