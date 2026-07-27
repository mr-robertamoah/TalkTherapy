<?php

namespace App\Actions\Request;

use App\Actions\Action;
use App\Enums\RequestTypeEnum;
use App\Http\Resources\AdminCounsellorVerificationRequestResource;
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
                ]
            )
        ) {
            return new RequestResource($request);
        }

        // Only RequestTypeEnum::counsellor is actually created anywhere in the codebase for the
        // remaining case (administrator is a defined enum value with no creation path today) --
        // AdminCounsellorVerificationRequestResource assumes `from` is a Counsellor, which only
        // holds for that type.
        return new AdminCounsellorVerificationRequestResource($request);
    }
}
