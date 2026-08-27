<?php

namespace App\Actions\Request;

use App\Actions\Action;
use App\DTOs\RequestResponseDTO;
use App\Enums\RequestTypeEnum;

class RespondToRequestAction extends Action
{
    public function execute(RequestResponseDTO $requestResponseDTO)
    {
        $request = $requestResponseDTO->request;

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

        // TODO respond to other requests
        // (SCRUM-119/120: this per-type dispatch chain is accepted, tracked debt -- see the
        // architect note on documentation/implementation_plan.md's Epic TT-6. A follow-up to
        // extract a type->handler map is worth filing independently of the Organizations work.)

        return $request->refresh();
    }
}
