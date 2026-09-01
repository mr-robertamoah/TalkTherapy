<?php

namespace App\Actions\Request;

use App\Actions\Action;
use App\Actions\SessionScheduleProposal\AcceptSessionScheduleProposalAction;
use App\Actions\SessionScheduleProposal\RejectSessionScheduleProposalAction;
use App\DTOs\RequestResponseDTO;
use App\Enums\RequestStatusEnum;
use App\Models\Request;

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
            return AcceptSessionScheduleProposalAction::new()->execute($requestResponseDTO);
        }

        return RejectSessionScheduleProposalAction::new()->execute($requestResponseDTO);
    }
}
