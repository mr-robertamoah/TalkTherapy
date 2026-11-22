<?php

namespace App\Actions\Request;

use App\Actions\Action;
use App\Actions\Recognition\RecordCounsellorRecognitionEventAction;
use App\DTOs\RequestResponseDTO;
use App\Enums\CounsellorRecognitionConsentEnum as Consent;
use App\Enums\CounsellorRecognitionEventEnum as Event;
use App\Enums\RequestStatusEnum;
use App\Models\CounsellorRecognition;
use App\Models\Request;
use Illuminate\Support\Facades\DB;

// TT-7.11d1/SCRUM-473: the counsellor answering a featuring consent request -- accepted or declined, written to the
// recognition with an event. Only the Counsellor it is addressed to gets here (EnsureUserCanRespondToRequestAction's
// `to` match; no administrator may answer it). A request whose recognition no longer waits on this answer (skipped,
// or the consent changed some other way) is made moot instead.
class RespondToCounsellorRecognitionRequestAction extends Action
{
    public function execute(RequestResponseDTO $requestResponseDTO)
    {
        return DB::transaction(function () use ($requestResponseDTO) {
            $request = Request::query()->lockForUpdate()->findOrFail($requestResponseDTO->request->id);

            if ($request->status != RequestStatusEnum::pending->value) {
                return $request;
            }

            $recognition = $request->for_type === CounsellorRecognition::class
                ? CounsellorRecognition::query()->lockForUpdate()->find($request->for_id)
                : null;

            if (! $recognition?->isApproved() || $recognition->consent !== Consent::pending) {
                $request->update(['status' => RequestStatusEnum::inconsequencial->value]);

                return $request;
            }

            $accepted = strtoupper((string) $requestResponseDTO->response) === RequestStatusEnum::accepted->value;

            $request->update(['status' => $accepted ? RequestStatusEnum::accepted->value : RequestStatusEnum::rejected->value]);

            $recognition->forceFill([
                'consent' => $accepted ? Consent::accepted : Consent::declined,
                'consent_changed_at' => now(),
            ])->save();

            RecordCounsellorRecognitionEventAction::new()->execute(
                $recognition,
                $accepted ? Event::consentAccepted : Event::consentDeclined,
                $requestResponseDTO->user,
                ['requestId' => $request->id],
            );

            return $request;
        });
    }
}
