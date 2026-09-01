<?php

namespace App\Services;

use App\Actions\Request\EnsureRequestExistsAction;
use App\Actions\Request\EnsureUserCanRespondToRequestAction;
use App\Actions\SessionScheduleProposal\CounterOfferSessionScheduleProposalAction;
use App\Actions\SessionScheduleProposal\EnsureCanProposeSessionScheduleAction;
use App\Actions\SessionScheduleProposal\EnsureNoPendingSessionScheduleProposalAction;
use App\Actions\SessionScheduleProposal\EnsureSessionScheduleProposalDataIsValidAction;
use App\Actions\SessionScheduleProposal\ProposeSessionScheduleAction;
use App\Actions\Therapy\EnsureTherapyExistsAction;
use App\DTOs\RequestResponseDTO;
use App\DTOs\SessionScheduleProposalDTO;
use App\Enums\RequestTypeEnum;
use App\Exceptions\RequestNotFoundException;
use App\Models\Request;
use App\Models\Therapy;
use Illuminate\Support\Facades\DB;

/**
 * Class SessionScheduleProposalService
 *
 * Handles a client or counsellor proposing a session day/time for a Therapy, and the other party
 * accepting/countering/rejecting it -- SCRUM-22/TT-2.5. No Session row is ever created by the
 * propose step; that only happens on accept (TT-2.5b).
 */
class SessionScheduleProposalService extends Service
{
    public function propose(SessionScheduleProposalDTO $dto): Request
    {
        EnsureTherapyExistsAction::new()->execute($dto);

        // Locking the Therapy row -- and re-running every check against THAT freshly-locked row,
        // not the pre-lock instance the controller originally loaded -- closes two races at once:
        // (1) the TOCTOU race EnsureNoPendingOrganizationCounsellorCompensationRequestAction's
        // caller guards against (two concurrent proposals for the same therapy), and (2) a
        // security-review finding that a counsellor could be assigned/removed via
        // PerformTherapyCounsellorLinkAction in the window between the controller's initial read
        // and this lock, which would otherwise leave EnsureCanProposeSessionScheduleAction and
        // ProposeSessionScheduleAction's from/to resolution reasoning about stale, no-longer-true
        // state.
        return DB::transaction(function () use ($dto) {
            $dto->therapy = Therapy::query()->lockForUpdate()->findOrFail($dto->therapy->id);

            EnsureCanProposeSessionScheduleAction::new()->execute($dto);

            EnsureSessionScheduleProposalDataIsValidAction::new()->execute($dto);

            EnsureNoPendingSessionScheduleProposalAction::new()->execute($dto);

            return ProposeSessionScheduleAction::new()->execute($dto);
        });
    }

    // Mirrors OrganizationCounsellorCompensationService::counterOffer() exactly: the type-check
    // guard exists because this endpoint is reached directly by requestId (not via
    // RespondToRequestAction's own per-type dispatch), so EnsureUserCanRespondToRequestAction's
    // type-agnostic `to`-party check alone isn't enough -- without it, a user legitimately `to`
    // on some unrelated pending request could have it force-rejected as if it were a schedule
    // negotiation (same class of gap the compensation flow's own PR #86 security review found).
    public function counterOffer(SessionScheduleProposalDTO $dto): Request
    {
        EnsureRequestExistsAction::new()->execute(RequestResponseDTO::new()->fromArray(['request' => $dto->request]));

        if ($dto->request->type !== RequestTypeEnum::sessionScheduleProposal->value) {
            throw new RequestNotFoundException('Request was not found.', 422);
        }

        EnsureUserCanRespondToRequestAction::new()->execute(
            RequestResponseDTO::new()->fromArray(['user' => $dto->user, 'request' => $dto->request])
        );

        EnsureSessionScheduleProposalDataIsValidAction::new()->execute($dto);

        return CounterOfferSessionScheduleProposalAction::new()->execute($dto);
    }
}
