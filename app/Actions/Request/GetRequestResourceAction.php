<?php

namespace App\Actions\Request;
use App\Actions\Action;
use App\Enums\RequestTypeEnum;
use App\Http\Resources\AdminCounsellorVerificationRequestResource;
use App\Http\Resources\RequestResource;
use App\Models\Request;
use MrRobertAmoah\DTO\BaseDTO;

class GetRequestResourceAction extends Action
{
    public function execute(Request $request)
    {
        if ($request->type == RequestTypeEnum::therapy->value)
            return new RequestResource($request);

        return new AdminCounsellorVerificationRequestResource($request);
    }
}