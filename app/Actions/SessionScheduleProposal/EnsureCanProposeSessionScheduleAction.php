<?php

namespace App\Actions\SessionScheduleProposal;

use App\Actions\Action;
use App\DTOs\SessionScheduleProposalDTO;
use App\Enums\TherapyStatusEnum;
use App\Exceptions\SessionException;

class EnsureCanProposeSessionScheduleAction extends Action
{
    // Either participant of the Therapy (the client who owns it, or its assigned counsellor) may
    // propose -- Therapy::isParticipant() already covers both, an existing check confirmed by
    // architect review rather than a bespoke "client only" restriction. The direction (who ends
    // up `to`) is resolved by ProposeSessionScheduleAction from whichever side the acting user
    // ISN'T, so this doesn't allow a self-addressed proposal.
    public function execute(SessionScheduleProposalDTO $dto)
    {
        // No therapy details in this specific message -- unlike the checks below, this one can
        // be reached by someone who is NOT a participant at all (any authenticated user can hit
        // this endpoint with any therapyId), so it must not leak the name of a private/anonymous
        // therapy they have no right to know about (security review, SCRUM-206; the same
        // enumeration class of finding as SCRUM-124/162, referenced in RequestResource). Every
        // check below this point only runs once participancy is already confirmed, so including
        // the therapy's own name in those messages discloses nothing the caller doesn't already
        // legitimately know.
        if ($dto->therapy->isNotParticipant($dto->user)) {
            throw new SessionException('You are not allowed to propose a session schedule for this therapy.', 422);
        }

        // A still-unmatched therapy (no counsellor assigned yet -- RespondToTherapyAssistanceRequestAction
        // only ever sets counsellor_id together with the in_session transition) has no one to
        // address the proposal `to`. Without this check, ProposeSessionScheduleAction would
        // persist a Request with a null `to`, and EnsureNoPendingSessionScheduleProposalAction
        // would then permanently block any future legitimate proposal for that therapy.
        if ($dto->therapy->doesNotHaveAssistance()) {
            throw new SessionException("Therapy with name: {$dto->therapy->name} has no assigned counsellor yet.", 422);
        }

        if ($dto->therapy->status === TherapyStatusEnum::ended->value) {
            throw new SessionException("You cannot propose a session schedule for therapy with name: {$dto->therapy->name} because it has ended.", 422);
        }

        // Mirrors EnsureCanCreateSessionAction's identical rule -- no point negotiating a new
        // schedule while a session is already active.
        if ($dto->therapy->activeSession) {
            throw new SessionException("You cannot propose a session schedule for therapy with name: {$dto->therapy->name} because it has an active session.", 422);
        }
    }
}
