<?php

namespace App\Actions\Request;

use App\Actions\Action;
use App\Actions\Organization\CreateOrganizationMemberAction;
use App\DTOs\RequestResponseDTO;
use App\Enums\RequestStatusEnum;
use App\Exceptions\GuardianRequiredException;
use App\Exceptions\OrganizationException;
use App\Models\Request;
use Illuminate\Support\Facades\DB;

class RespondToOrganizationMemberRequestAction extends Action
{
    // Shared by both organizationMemberInvite and organizationMemberApplication -- mirrors
    // RespondToOrganizationCounsellorRequestAction's shape.
    public function execute(RequestResponseDTO $requestResponseDTO)
    {
        return DB::transaction(function () use ($requestResponseDTO) {
            $request = Request::query()->lockForUpdate()->findOrFail($requestResponseDTO->request->id);

            // SCRUM-417: withdrawn while they answered -- say so, not a silent no-op that looks like an answer.
            if ($request->wasWithdrawn()) {
                throw new OrganizationException(EnsureRequestIsStillPendingAction::WITHDRAWN, 422);
            }

            if ($request->status != RequestStatusEnum::pending->value) {
                return $request;
            }

            $status = is_null($requestResponseDTO->response)
                ? RequestStatusEnum::rejected->value
                : strtoupper($requestResponseDTO->response);

            // Re-check the org's own eligibility at accept time (it may have lost verification
            // or its is_consumer flag since the request was created) -- rejecting a stale
            // request is always fine; accepting one for a now-ineligible org is not.
            if ($status === RequestStatusEnum::accepted->value) {
                $organization = $request->for;

                if ($organization->isNotVerified() || ! $organization->is_consumer) {
                    throw new OrganizationException('This organization is no longer eligible to accept members.', 422);
                }

                // SCRUM-416: a minor waiting for a guardian doesn't become a member -- told plainly to the
                // minor accepting an invitation, neutrally to an admin accepting their application (as a
                // group's creator is, RespondToGroupTherapyMembershipRequestAction).
                $member = CreateOrganizationMemberAction::memberOf($request);

                if ($member?->isPausedForMissingGuardian(fresh: true)) {
                    throw $member->is($requestResponseDTO->user)
                        ? GuardianRequiredException::forOrganizationMember()
                        : GuardianRequiredException::requestUnavailable();
                }
            }

            $request->update(['status' => $status]);

            $request = $request->refresh();

            if ($status === RequestStatusEnum::accepted->value) {
                CreateOrganizationMemberAction::new()->execute($request);
            }

            return $request;
        });
    }
}
