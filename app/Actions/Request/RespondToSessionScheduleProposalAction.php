<?php

namespace App\Actions\Request;

use App\Actions\Action;
use App\Actions\SessionScheduleProposal\AcceptSessionScheduleProposalAction;
use App\Actions\SessionScheduleProposal\RejectSessionScheduleProposalAction;
use App\Actions\Therapy\EnsureTherapyCanProceedAction;
use App\DTOs\RequestResponseDTO;
use App\Enums\RequestStatusEnum;
use App\Models\Counsellor;
use App\Models\Request;
use App\Models\Therapy;

class RespondToSessionScheduleProposalAction extends Action
{
    // Who may respond, and that a pending request even exists, is already fully gated upstream
    // by EnsureUserCanRespondToRequestAction (its `to`-party check already covers this type's
    // `to` being a User or a Counsellor) -- mirrors RespondToOrganizationCounsellorCompensationRequestAction's
    // shape of needing no bespoke authorization action here.
    public function execute(RequestResponseDTO $requestResponseDTO): Request
    {
        $response = is_null($requestResponseDTO->response)
            ? RequestStatusEnum::rejected->value
            : strtoupper($requestResponseDTO->response);

        if ($response === RequestStatusEnum::accepted->value) {
            // SCRUM-288: accepting books a session, so it is gated like proposing one. Rejecting
            // stays allowed (it only closes the offer).
            // Whoever answers a proposal addressed to a counsellor answers as that counsellor, even
            // if the therapy no longer lists them (that case goes stale below).
            $request = $requestResponseDTO->request;
            EnsureTherapyCanProceedAction::new()->execute(
                $requestResponseDTO->user,
                $request->for instanceof Therapy ? $request->for : null,
                actsAsCounsellor: $request->to instanceof Counsellor ? true : null,
            );

            return AcceptSessionScheduleProposalAction::new()->execute($requestResponseDTO);
        }

        return RejectSessionScheduleProposalAction::new()->execute($requestResponseDTO);
    }
}
