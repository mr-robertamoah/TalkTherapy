<?php

namespace App\Services;

use App\Actions\Request\EnsureRequestExistsAction;
use App\Actions\Request\EnsureRequestIsStillPendingAction;
use App\Actions\Request\EnsureRequestResponseIsValidAction;
use App\Actions\Request\EnsureUserCanRespondToRequestAction;
use App\Actions\Request\GetRequestResourceAction;
use App\Actions\Request\RespondToRequestAction;
use App\DTOs\GetVerificationRequestsDTO;
use App\DTOs\RequestResponseDTO;
use App\Enums\PaginationEnum;
use App\Enums\RequestTypeEnum;
use App\Http\Resources\AdminCounsellorVerificationRequestResource;
use App\Http\Resources\RequestResource;
use App\Models\Organization;
use App\Models\Request;
use App\Models\User;

class RequestService extends Service
{
    public function getRequests(string $status, User $user)
    {
        $query = Request::query();

        $query->where(function ($query) use ($status, $user) {
            $query->whereFrom($user);
            if ($status) {
                $query->where('status', $status);
            }
        });

        $query->orWhere(function ($query) use ($status, $user) {
            $query->whereTo($user);
            if ($status) {
                $query->where('status', $status);
            }
        });

        $counsellor = $user->counsellor;
        $query->when($counsellor, function ($query) use ($counsellor, $status) {

            $query->orWhere(function ($query) use ($status, $counsellor) {
                $query->whereFrom($counsellor);
                if ($status) {
                    $query->where('status', $status);
                }
            });
            $query->orWhere(function ($query) use ($status, $counsellor) {
                $query->whereTo($counsellor);
                if ($status) {
                    $query->where('status', $status);
                }
            });
        });

        // TT-6.6d (SCRUM-162): org-directed requests (counsellor applications, member
        // applications, invites) address to/from as the Organization itself, not the admin --
        // an org admin has no way to list "pending for my org" without this. Additive to the
        // listing query only, mirroring the $counsellor block above but matching any one of the
        // admin's organizations rather than a single model instance.
        $administeredOrganizationIds = $user->administeredOrganizations()->pluck('organizations.id');
        $query->when($administeredOrganizationIds->isNotEmpty(), function ($query) use ($administeredOrganizationIds, $status) {

            $query->orWhere(function ($query) use ($status, $administeredOrganizationIds) {
                $query->where('from_type', Organization::class)->whereIn('from_id', $administeredOrganizationIds);
                if ($status) {
                    $query->where('status', $status);
                }
            });
            $query->orWhere(function ($query) use ($status, $administeredOrganizationIds) {
                $query->where('to_type', Organization::class)->whereIn('to_id', $administeredOrganizationIds);
                if ($status) {
                    $query->where('status', $status);
                }
            });
        });

        // Not the actual enforcement -- SQL AND-binds-tighter-than-OR precedence means this only
        // attaches to the last orWhere'd branch above. Each branch already applies its own
        // internal `if ($status)` filter (required, not redundant); this is just a no-op safety
        // net, reviewed and confirmed correct during SCRUM-162.
        if ($status) {
            $query->where('status', $status);
        }

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

    public function respondToRequest(RequestResponseDTO $requestResponseDTO)
    {
        EnsureRequestExistsAction::new()->execute($requestResponseDTO);

        EnsureUserCanRespondToRequestAction::new()->execute($requestResponseDTO);

        // Every RespondTo*RequestAction writes strtoupper($response) straight to status --
        // reject anything that isn't accepted/rejected before any of them run (SCRUM-89).
        EnsureRequestResponseIsValidAction::new()->execute($requestResponseDTO);

        // SCRUM-171: an already-decided request's own RespondTo*RequestAction already no-ops
        // safely (SCRUM-80/91) rather than corrupting its status, but previously did so behind a
        // misleading 201 success -- this surfaces that case as a clean 422 instead. See
        // EnsureRequestIsStillPendingAction's own comment for the full rationale.
        EnsureRequestIsStillPendingAction::new()->execute($requestResponseDTO);

        $request = RespondToRequestAction::new()->execute($requestResponseDTO);

        // TODO dispatch event
        return GetRequestResourceAction::new()->execute($request);
    }
}
