<?php

namespace App\Actions\Request;

use App\Actions\Action;
use App\Enums\RequestTypeEnum;
use App\Http\Resources\AdminCounsellorVerificationRequestResource;
use App\Http\Resources\OrganizationRequestResource;
use App\Http\Resources\RequestResource;
use App\Models\Request;

class GetRequestResourceAction extends Action
{
    public function execute(Request $request)
    {
        if (
            in_array(
                $request->type,
                [
                    RequestTypeEnum::therapy->value,
                    RequestTypeEnum::guardianship->value,
                    RequestTypeEnum::discussion->value,
                    RequestTypeEnum::groupTherapy->value,
                    RequestTypeEnum::groupTherapyMembership->value,
                    // SCRUM-206 (TT-2.5a): `for` is a Therapy, `from`/`to` are User/Counsellor --
                    // the same shape RequestResource already handles for `therapy` above.
                    RequestTypeEnum::sessionScheduleProposal->value,
                ]
            )
        ) {
            return new RequestResource($request);
        }

        // SCRUM-120: these are the only types where `from`/`to` can be an Organization, which
        // neither RequestResource nor AdminCounsellorVerificationRequestResource account for.
        if (
            in_array(
                $request->type,
                [
                    RequestTypeEnum::organization->value,
                    RequestTypeEnum::organizationCounsellorInvite->value,
                    RequestTypeEnum::organizationCounsellorApplication->value,
                    RequestTypeEnum::organizationMemberInvite->value,
                    RequestTypeEnum::organizationMemberApplication->value,
                    // SCRUM-146 (TT-6.4c): `for` is an OrganizationCounsellor affiliation, not an
                    // Organization directly -- OrganizationRequestResource resolves through it.
                    RequestTypeEnum::organizationCounsellorCompensationChange->value,
                ]
            )
        ) {
            return new OrganizationRequestResource($request);
        }

        // Only RequestTypeEnum::counsellor is actually created anywhere in the codebase for the
        // remaining case (administrator is a defined enum value with no creation path today) --
        // AdminCounsellorVerificationRequestResource assumes `from` is a Counsellor, which only
        // holds for that type.
        return new AdminCounsellorVerificationRequestResource($request);
    }
}
