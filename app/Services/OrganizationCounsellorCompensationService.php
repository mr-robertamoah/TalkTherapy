<?php

namespace App\Services;

use App\Actions\Organization\CounterOfferOrganizationCounsellorCompensationChangeAction;
use App\Actions\Organization\EnsureNoPendingOrganizationCounsellorCompensationRequestAction;
use App\Actions\Organization\EnsureOrganizationCounsellorCompensationDataIsValidAction;
use App\Actions\Organization\EnsureOrganizationCounsellorExistsAction;
use App\Actions\Organization\EnsureUserCanSetOrganizationCounsellorCompensationAction;
use App\Actions\Organization\EnsureUserCanViewOrganizationCounsellorCompensationsAction;
use App\Actions\Organization\ProposeOrganizationCounsellorCompensationChangeAction;
use App\Actions\Request\EnsureRequestExistsAction;
use App\Actions\Request\EnsureUserCanRespondToRequestAction;
use App\DTOs\OrganizationCounsellorCompensationDTO;
use App\DTOs\RequestResponseDTO;
use App\Enums\RequestTypeEnum;
use App\Exceptions\RequestNotFoundException;
use App\Models\OrganizationCounsellor;
use App\Models\Request;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class OrganizationCounsellorCompensationService extends Service
{
    // SCRUM-146 (TT-6.4c): was setCompensation() -- an org admin's write was previously
    // unilateral and immediately effective. Now creates a proposal the counsellor must accept
    // (SCRUM-147) before organization_counsellor_compensations ever sees a new row.
    public function proposeCompensationChange(OrganizationCounsellorCompensationDTO $dto): Request
    {
        EnsureOrganizationCounsellorExistsAction::new()->execute($dto);

        EnsureUserCanSetOrganizationCounsellorCompensationAction::new()->execute($dto);

        EnsureOrganizationCounsellorCompensationDataIsValidAction::new()->execute($dto);

        // SCRUM-147 review: EnsureNoPendingOrganizationCounsellorCompensationRequestAction's
        // check-then-create was a TOCTOU race -- two concurrent proposals for the same
        // affiliation could both pass the "no pending" check before either committed. Locking
        // the affiliation row for the duration of the check+create serializes proposal creation
        // per affiliation, so at most one pending request can ever exist for it at a time.
        return DB::transaction(function () use ($dto) {
            OrganizationCounsellor::query()->lockForUpdate()->findOrFail($dto->organizationCounsellor->id);

            EnsureNoPendingOrganizationCounsellorCompensationRequestAction::new()->execute($dto);

            return ProposeOrganizationCounsellorCompensationChangeAction::new()->execute($dto);
        });
    }

    // SCRUM-148 (TT-6.4c): the party currently addressed `to` the pending request can counter
    // rather than just accept/reject -- reuses the same generic to-party authorization as
    // accept/reject (EnsureUserCanRespondToRequestAction), since counter-offering is just another
    // way of responding to a pending request, not a distinct capability needing its own gate.
    public function counterOffer(OrganizationCounsellorCompensationDTO $dto): Request
    {
        EnsureRequestExistsAction::new()->execute(RequestResponseDTO::new()->fromArray(['request' => $dto->request]));

        // Security review (PR #86): EnsureUserCanRespondToRequestAction is type-agnostic (it
        // only checks the `to`-party) -- unlike accept/reject, which is only ever reached via
        // RespondToRequestAction's own per-type dispatch, this endpoint is reached directly by
        // requestId with no such filtering. Without this check, a user legitimately `to` on some
        // unrelated pending request (a group-therapy invite, a guardianship request, ...) could
        // have it force-rejected and mutated as if it were a compensation negotiation. Reuses
        // EnsureRequestExistsAction's exact "not found" message -- the wrong type should be
        // indistinguishable from a non-existent request to whoever's asking.
        if ($dto->request->type !== RequestTypeEnum::organizationCounsellorCompensationChange->value) {
            throw new RequestNotFoundException('Request was not found.', 422);
        }

        EnsureUserCanRespondToRequestAction::new()->execute(
            RequestResponseDTO::new()->fromArray(['user' => $dto->user, 'request' => $dto->request])
        );

        EnsureOrganizationCounsellorCompensationDataIsValidAction::new()->execute($dto);

        return CounterOfferOrganizationCounsellorCompensationChangeAction::new()->execute($dto);
    }

    public function getCompensations(OrganizationCounsellorCompensationDTO $dto): Collection
    {
        EnsureOrganizationCounsellorExistsAction::new()->execute($dto);

        EnsureUserCanViewOrganizationCounsellorCompensationsAction::new()->execute($dto);

        return $dto->organizationCounsellor->compensations()
            ->with('setBy')
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->get();
    }
}
