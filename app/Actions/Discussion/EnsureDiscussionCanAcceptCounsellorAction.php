<?php

namespace App\Actions\Discussion;

use App\Actions\Action;
use App\DTOs\GetDiscussionsDTO;
use App\Exceptions\DiscussionException;

class EnsureDiscussionCanAcceptCounsellorAction extends Action
{
    // Called on an already lockForUpdate()-locked Discussion, from inside the same transaction
    // as the attach it's gating (SCRUM-23/TT-2.4) -- both call sites (RespondToDiscussionRequestAction,
    // PerformDiscussionRequestLinkAction) already lock a different row (Request/Link respectively)
    // for their own race-condition guarantees; locking the Discussion row too is what makes two
    // concurrent accepts targeting the same discussion actually serialize against each other,
    // rather than both reading a stale, under-capacity count.
    //
    // The count query itself is ALSO a locking read (lockForUpdate), not just the Discussion row
    // lock above -- under MySQL's default REPEATABLE-READ isolation, a plain (non-locking) SELECT
    // can still return a stale snapshot even after successfully waiting on an unrelated row's
    // lock, if any earlier plain read in the same transaction already pinned that snapshot. A
    // locking read always reads latest-committed data regardless of snapshot timing, so this is
    // correct independent of whatever else happens earlier in the caller's transaction (security
    // review, SCRUM-23 -- empirically reproduced against a real MySQL connection).
    public function execute(GetDiscussionsDTO $getDiscussionsDTO)
    {
        $discussion = $getDiscussionsDTO->discussion;

        if (is_null($discussion->max_counsellors)) {
            return;
        }

        if ($discussion->counsellors()->lockForUpdate()->count() >= $discussion->max_counsellors) {
            throw new DiscussionException("'{$discussion->name}' discussion has already reached its maximum number of counsellors.", 422);
        }
    }
}
