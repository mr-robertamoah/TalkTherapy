<?php

namespace App\Actions\Request;

use App\Actions\Action;
use App\Actions\Organization\CreateOrganizationCounsellorAffiliationAction;
use App\DTOs\RequestResponseDTO;
use App\Enums\RequestStatusEnum;
use App\Exceptions\OrganizationException;
use App\Models\Request;
use Illuminate\Support\Facades\DB;

class RespondToOrganizationCounsellorRequestAction extends Action
{
    // Shared by both organizationCounsellorInvite and organizationCounsellorApplication.
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

            // Verified/provider status, and the counsellor's own verification, were only
            // checked at request-creation time -- either could have changed since (e.g. a
            // fraud finding, or a counsellor whose license lapsed). Rejecting a stale request
            // is always fine; accepting one for a now-ineligible org/counsellor is not.
            if ($status === RequestStatusEnum::accepted->value) {
                $organization = $request->for;

                if ($organization->isNotVerified() || ! $organization->is_provider) {
                    throw new OrganizationException('This organization is no longer eligible to accept counsellor affiliations.', 422);
                }
            }

            $request->update(['status' => $status]);

            $request = $request->refresh();

            // SCRUM-121: turns the now-accepted request into an actual (pending) affiliation
            // row -- this also re-verifies the counsellor and throws (rolling back the status
            // update above) if they're no longer platform-verified.
            if ($status === RequestStatusEnum::accepted->value) {
                CreateOrganizationCounsellorAffiliationAction::new()->execute($request);
            }

            return $request;
        });
    }
}
