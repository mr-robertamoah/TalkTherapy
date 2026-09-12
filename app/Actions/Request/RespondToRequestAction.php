<?php

namespace App\Actions\Request;

use App\Actions\Action;
use App\DTOs\RequestResponseDTO;
use App\Enums\RequestTypeEnum;
use App\Exceptions\BadRequestException;

class RespondToRequestAction extends Action
{
    public function execute(RequestResponseDTO $requestResponseDTO)
    {
        $request = $requestResponseDTO->request;

        // TT-4.10c/SCRUM-292: EnsureDobChangeIsAllowedAction can already create a dobChange-type
        // request, but the approve/reject action that actually applies it (and its retroactive
        // snapshot correction) is TT-4.10d's job, not yet built -- without this guard, hitting
        // this shared endpoint against a dobChange request would silently no-op (falling through
        // every branch below, status left PENDING) while still reporting a misleading success,
        // the exact same response-honesty gap SCRUM-171 fixed for an already-decided request.
        if ($request->type == RequestTypeEnum::dobChange->value) {
            throw new BadRequestException('Responding to a date-of-birth change request is not yet supported here.', 422);
        }

        if ($requestResponseDTO->request->type == RequestTypeEnum::counsellor->value) {
            $request = RespondToCounsellorVerificationRequestAction::new()->execute($requestResponseDTO);
        }

        if ($requestResponseDTO->request->type == RequestTypeEnum::therapy->value) {
            $request = RespondToTherapyAssistanceRequestAction::new()->execute($requestResponseDTO);
        }

        if ($requestResponseDTO->request->type == RequestTypeEnum::guardianship->value) {
            $request = RespondToGuardianshipRequestAction::new()->execute($requestResponseDTO);
        }

        if ($requestResponseDTO->request->type == RequestTypeEnum::discussion->value) {
            $request = RespondToDiscussionRequestAction::new()->execute($requestResponseDTO);
        }

        if ($requestResponseDTO->request->type == RequestTypeEnum::groupTherapyMembership->value) {
            $request = RespondToGroupTherapyMembershipRequestAction::new()->execute($requestResponseDTO);
        }

        if ($requestResponseDTO->request->type == RequestTypeEnum::organization->value) {
            $request = RespondToOrganizationVerificationRequestAction::new()->execute($requestResponseDTO);
        }

        if (in_array($requestResponseDTO->request->type, [
            RequestTypeEnum::organizationCounsellorInvite->value,
            RequestTypeEnum::organizationCounsellorApplication->value,
        ])) {
            $request = RespondToOrganizationCounsellorRequestAction::new()->execute($requestResponseDTO);
        }

        if (in_array($requestResponseDTO->request->type, [
            RequestTypeEnum::organizationMemberInvite->value,
            RequestTypeEnum::organizationMemberApplication->value,
        ])) {
            $request = RespondToOrganizationMemberRequestAction::new()->execute($requestResponseDTO);
        }

        if ($requestResponseDTO->request->type == RequestTypeEnum::organizationCounsellorCompensationChange->value) {
            $request = RespondToOrganizationCounsellorCompensationRequestAction::new()->execute($requestResponseDTO);
        }

        if ($requestResponseDTO->request->type == RequestTypeEnum::sessionScheduleProposal->value) {
            $request = RespondToSessionScheduleProposalAction::new()->execute($requestResponseDTO);
        }

        if ($requestResponseDTO->request->type == RequestTypeEnum::refund->value) {
            $request = RespondToRefundRequestAction::new()->execute($requestResponseDTO);
        }

        // TODO respond to other requests
        // (SCRUM-119/120: this per-type dispatch chain is accepted, tracked debt -- see the
        // architect note on documentation/implementation_plan.md's Epic TT-6. A follow-up to
        // extract a type->handler map is worth filing independently of the Organizations work.)

        return $request->refresh();
    }
}
