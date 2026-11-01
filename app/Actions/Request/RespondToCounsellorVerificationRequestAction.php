<?php

namespace App\Actions\Request;

use App\Actions\Action;
use App\DTOs\RequestResponseDTO;
use App\Enums\RequestStatusEnum;
use App\Exceptions\InvalidVerificationRequestData;
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

            $status = is_null($requestResponseDTO->response)
                ? RequestStatusEnum::rejected->value
                : strtoupper($requestResponseDTO->response);

            // SCRUM-338: only an ACCEPTED request validates the licenses (and a not-yet-validated
            // licensing authority). This used to run before the response was even read, so a
            // rejection left both licenses validated -- and hasNationalIdentification() true for
            // a counsellor whose ID was just rejected.
            if ($status == RequestStatusEnum::accepted->value) {
                $nationalIdLicense = License::find($request->data['nationalIdLicense'] ?? null);
                $otherLicense = License::find($request->data['otherLicense'] ?? null);

                // Review finding: never accept a verification with nothing to validate.
                if (! $nationalIdLicense || ! $otherLicense) {
                    throw new InvalidVerificationRequestData('This verification request is missing its documents and cannot be accepted.', 422);
                }

                $nationalIdLicense->validate();
                $otherLicense->validate();

                if ($otherLicense->licensingAuthority?->isNotValidated()) {
                    $otherLicense->licensingAuthority->validate();
                }
            }

            $request->update(['status' => $status]);

            $request = $request->refresh();

            if ($request->status == RequestStatusEnum::accepted->value) {
                $request->from->verify();
            }

            return $request;
        });
    }
}
