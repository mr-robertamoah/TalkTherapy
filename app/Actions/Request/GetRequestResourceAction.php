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
                ]
            )
        ) return new RequestResource($request);

        return new AdminCounsellorVerificationRequestResource($request);
    }
}