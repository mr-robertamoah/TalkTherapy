<?php

namespace App\Actions\Request;

use App\Actions\Action;
use App\DTOs\RequestResponseDTO;
use App\Enums\RequestStatusEnum;
use App\Models\License;
use App\Models\Request;
use Illuminate\Support\Facades\DB;

class RespondToCounsellorVerificationRequestAction extends Action
{
    public function execute(RequestResponseDTO $requestResponseDTO)
    {
        // Locking the request row and re-checking its status inside the lock closes the same
        // double-submission race SCRUM-80 fixed for group therapy membership requests: two
        // concurrent responses to the same request both queue on this lock, so the second one
        // always observes the first's committed status update and just no-ops (SCRUM-91).
        return DB::transaction(function () use ($requestResponseDTO) {
            $request = Request::query()->lockForUpdate()->findOrFail($requestResponseDTO->request->id);

            if ($request->status != RequestStatusEnum::pending->value) {
                return $request;
            }

            $nationalIdLicense = License::find($request->data['nationalIdLicense']);

            $nationalIdLicense->validate();

            $otherLicense = License::find($request->data['otherLicense']);

            $otherLicense->validate();

            if ($otherLicense->licensingAuthority->isNotValidated()) {
                $otherLicense->licensingAuthority->validate();
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
