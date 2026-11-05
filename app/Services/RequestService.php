<?php

namespace App\Services;

use App\Actions\Guardianship\EnsureMinorHasActiveGuardianAction;
use App\Actions\Request\EnsureRequestExistsAction;
use App\Actions\Request\EnsureRequestIsStillPendingAction;
use App\Actions\Request\EnsureRequestResponseIsValidAction;
use App\Actions\Request\EnsureRequestResponseReasonIsValidAction;
use App\Actions\Request\EnsureUserCanRespondToRequestAction;
use App\Actions\Request\GetPendingRefundRequestsForAdminAction;
use App\Actions\Request\GetRequestResourceAction;
use App\Actions\Request\RespondToRequestAction;
use App\Actions\Suspension\EnsureCanActAsCounsellorAction;
use App\DTOs\GetVerificationRequestsDTO;
use App\DTOs\RequestResponseDTO;
use App\Enums\PaginationEnum;
use App\Enums\RequestStatusEnum;
use App\Enums\RequestTypeEnum;
use App\Http\Resources\AdminCounsellorVerificationRequestResource;
use App\Http\Resources\RequestResource;
use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Models\Organization;
use App\Models\Request;
use App\Models\Therapy;
use App\Models\User;

class RequestService extends Service
{
    public function getRequests(string $status, User $user)
    {
        $counsellor = $user->counsellor;
        // TT-6.6d (SCRUM-162): org-directed requests (counsellor applications, member
        // applications, invites) address to/from as the Organization itself, not the admin.
        $administeredOrganizationIds = $user->administeredOrganizations()->pluck('organizations.id');
        // TT-4.10f/SCRUM-295: ANY of a ward's guardians may respond to a dobChange request, not
        // just the one `to` was fixed to at creation, so every guardian must be able to list it.
        $wardIds = $user->wards()->pluck('ward_id');
        $isAdmin = $user->isAdmin();
        $isSuperAdmin = $isAdmin && $user->isSuperAdmin();

        // SCRUM-142: every "may see this request" branch sits inside one group, and the status
        // filter is applied once, outside it -- AND binds tighter than OR, so a status filter
        // added after a bare chain of orWhere()s would only attach to the last branch.
        $query = Request::query()->where(function ($query) use ($user, $counsellor, $administeredOrganizationIds, $wardIds, $isAdmin, $isSuperAdmin) {
            $query->whereFrom($user)->orWhere(fn ($query) => $query->whereTo($user));

            if ($counsellor) {
                $query->orWhere(fn ($query) => $query->whereFrom($counsellor))
                    ->orWhere(fn ($query) => $query->whereTo($counsellor));
            }

            if ($administeredOrganizationIds->isNotEmpty()) {
                $query->orWhere(fn ($query) => $query->where('from_type', Organization::class)->whereIn('from_id', $administeredOrganizationIds))
                    ->orWhere(fn ($query) => $query->where('to_type', Organization::class)->whereIn('to_id', $administeredOrganizationIds));
            }

            if ($wardIds->isNotEmpty()) {
                $query->orWhere(fn ($query) => $query->where('type', RequestTypeEnum::dobChange->value)
                    ->where('for_type', User::class)
                    ->whereIn('for_id', $wardIds));
            }

            // TT-4.10e/SCRUM-294 and TT-4.11d/SCRUM-305: a null-`to` dobChange or ageVerification
            // request ("any admin may respond") would otherwise appear in no one's listing.
            if ($isAdmin) {
                $query->orWhere(fn ($query) => $query
                    ->whereIn('type', [RequestTypeEnum::dobChange->value, RequestTypeEnum::ageVerification->value])
                    ->whereNull('to_id'));
            }

            // SCRUM-381: a counsellor's request for a safeguarding exception is for the super
            // administrator only (EnsureUserCanRespondToRequestAction), so only they list it.
            if ($isSuperAdmin) {
                $query->orWhere(fn ($query) => $query->where('type', RequestTypeEnum::safeguardingException->value)->whereNull('to_id'));
            }
        });

        $query->when($status, fn ($query) => $query->where('status', $status));

        return RequestResource::collection($query->latest()->paginate(
            PaginationEnum::preferencesPagination->value
        ));
    }

    public function getVerificationRequestsForCounsellors(GetVerificationRequestsDTO $getVerificationRequestsDTO)
    {
        if (is_null($getVerificationRequestsDTO->user) || $getVerificationRequestsDTO->user?->isNotAdmin()) {
            return [];
        }

        $query = Request::query();

        $query->where('type', RequestTypeEnum::counsellor->value);

        if ($getVerificationRequestsDTO->filterType == 'validated') {
            $query->where($getVerificationRequestsDTO->filterType, $getVerificationRequestsDTO->filterValue);
        }

        return AdminCounsellorVerificationRequestResource::collection($query->paginate(
            PaginationEnum::preferencesPagination->value
        ));
    }

    // TT-7.7c/SCRUM-251
    public function getPendingRefundRequestsForAdmin(?User $user)
    {
        return GetPendingRefundRequestsForAdminAction::new()->execute($user);
    }

    public function respondToRequest(RequestResponseDTO $requestResponseDTO)
    {
        EnsureRequestExistsAction::new()->execute($requestResponseDTO);

        EnsureUserCanRespondToRequestAction::new()->execute($requestResponseDTO);

        // Every RespondTo*RequestAction writes strtoupper($response) straight to status --
        // reject anything that isn't accepted/rejected before any of them run (SCRUM-89).
        EnsureRequestResponseIsValidAction::new()->execute($requestResponseDTO);

        EnsureRequestResponseReasonIsValidAction::new()->execute($requestResponseDTO);

        // SCRUM-171: an already-decided request's own RespondTo*RequestAction already no-ops
        // safely (SCRUM-80/91) rather than corrupting its status, but previously did so behind a
        // misleading 201 success -- this surfaces that case as a clean 422 instead. See
        // EnsureRequestIsStillPendingAction's own comment for the full rationale.
        EnsureRequestIsStillPendingAction::new()->execute($requestResponseDTO);

        $this->ensureNoSuspendedCounsellorIsTakenOn($requestResponseDTO);

        $request = RespondToRequestAction::new()->execute($requestResponseDTO);

        // TODO dispatch event
        return GetRequestResourceAction::new()->execute($request);
    }

    // SCRUM-359: the one place every request is accepted -- a suspended counsellor can't take on
    // work (as the recipient), and nobody can accept a suspended counsellor's offer (as the sender).
    private function ensureNoSuspendedCounsellorIsTakenOn(RequestResponseDTO $requestResponseDTO): void
    {
        $request = $requestResponseDTO->request;
        if (strtoupper((string) $requestResponseDTO->response) !== RequestStatusEnum::accepted->value || ! in_array($request->type, RequestTypeEnum::counsellingTypes(), true)) {
            return;
        }

        EnsureCanActAsCounsellorAction::new()->execute($request->to);
        EnsureCanActAsCounsellorAction::new()->execute($request->from, asTarget: true);

        // SCRUM-288: nor can a minor whose therapies are paused for a missing guardian accept one as
        // a client or member (whoever answers for a counsellor acts as one, and isn't asked). The
        // other party, where there is one, is checked by the request's own action under its lock.
        if (! $request->to instanceof Counsellor) {
            $for = $request->for;
            EnsureMinorHasActiveGuardianAction::new()->execute($requestResponseDTO->user, $for instanceof Therapy || $for instanceof GroupTherapy ? $for : null, counterparty: false);
        }
    }
}
