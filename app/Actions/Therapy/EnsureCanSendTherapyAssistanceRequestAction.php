<?php

namespace App\Actions\Therapy;

use App\Actions\Action;
use App\DTOs\AssistTherapyDTO;
use App\Exceptions\TherapyException;

// SCRUM-344: the authorization for POST /therapies/{therapyId}/assist, run before any check that
// could reveal anything about the therapy (it used to name the assigned counsellor to anyone).
// Allowed: the therapy's own client (inviting counsellors), or a counsellor offering to assist a
// PUBLIC therapy -- the only kind a counsellor can discover (TherapyService::getRandomTherapies()
// lists public ones). A counsellor could previously offer on any therapy id, private ones included.
// Deliberately refused too: a counsellor who can view a private therapy only through a discussion
// request about it, and an admin acting as a counsellor -- inviting counsellors onto a private
// therapy is its client's call, through the client branch.
class EnsureCanSendTherapyAssistanceRequestAction extends Action
{
    public function execute(AssistTherapyDTO $assistTherapyDTO): void
    {
        $therapy = $assistTherapyDTO->therapy;
        $user = $assistTherapyDTO->user;

        $isClient = $therapy->isUser($user);
        $isCounsellorOfferingOnPublicTherapy = $user->counsellor && $therapy->public;

        if ($isClient || $isCounsellorOfferingOnPublicTherapy) {
            return;
        }

        throw TherapyException::notAllowed();
    }
}
