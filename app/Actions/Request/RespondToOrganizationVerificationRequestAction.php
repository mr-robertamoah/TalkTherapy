<?php

namespace App\Actions\Request;

use App\Actions\Action;
use App\DTOs\RequestResponseDTO;
use App\Enums\RequestStatusEnum;
use App\Models\Request;
use Illuminate\Support\Facades\DB;

class RespondToOrganizationVerificationRequestAction extends Action
{
    public function execute(RequestResponseDTO $requestResponseDTO)
    {
        // Same lock-and-recheck pattern as RespondToCounsellorVerificationRequestAction, to
        // close the same double-submission race for concurrent responses to one request.
        return DB::transaction(function () use ($requestResponseDTO) {
            $request = Request::query()->lockForUpdate()->findOrFail($requestResponseDTO->request->id);

            if ($request->status != RequestStatusEnum::pending->value) {
                return $request;
            }

            $request->update([
                'status' => is_null($requestResponseDTO->response)
                    ? RequestStatusEnum::rejected->value
                    : strtoupper($requestResponseDTO->response),
            ]);

            $request = $request->refresh();

            if ($request->status == RequestStatusEnum::accepted->value) {
                $request->from->verify();
            }

            return $request;
        });
    }
}
