<?php

namespace App\Actions\Request;

use App\Actions\Action;
use App\DTOs\RequestResponseDTO;
use App\Enums\RequestStatusEnum;
use App\Exceptions\OrganizationException;
use App\Models\Request;
use Illuminate\Support\Facades\DB;

class RespondToOrganizationCounsellorRequestAction extends Action
{
    // Shared by both organizationCounsellorInvite and organizationCounsellorApplication --
    // accepting either is just a status transition here. Turning an accepted request into an
    // actual organization_counsellors row (once compensation terms are agreed) is TT-6.4a's
    // job, not this ticket's (SCRUM-120) -- affiliation isn't active until terms exist.
    public function execute(RequestResponseDTO $requestResponseDTO)
    {
        return DB::transaction(function () use ($requestResponseDTO) {
            $request = Request::query()->lockForUpdate()->findOrFail($requestResponseDTO->request->id);

            if ($request->status != RequestStatusEnum::pending->value) {
                return $request;
            }

            $status = is_null($requestResponseDTO->response)
                ? RequestStatusEnum::rejected->value
                : strtoupper($requestResponseDTO->response);

            // Verified/provider status was only checked at request-creation time -- an org
            // could have lost either since (e.g. a fraud finding). Rejecting a stale request
            // is always fine; accepting one for a now-ineligible org is not.
            if ($status === RequestStatusEnum::accepted->value) {
                $organization = $request->for;

                if ($organization->isNotVerified() || ! $organization->is_provider) {
                    throw new OrganizationException('This organization is no longer eligible to accept counsellor affiliations.', 422);
                }
            }

            $request->update(['status' => $status]);

            return $request->refresh();
        });
    }
}
