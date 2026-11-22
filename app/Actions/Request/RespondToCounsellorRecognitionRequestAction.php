<?php

namespace App\Actions\Request;

use App\Actions\Action;
use App\Actions\Recognition\RecordCounsellorRecognitionEventAction;
use App\DTOs\RequestResponseDTO;
use App\Enums\CounsellorRecognitionConsentEnum as Consent;
use App\Enums\CounsellorRecognitionEventEnum as Event;
use App\Enums\RequestStatusEnum;
use App\Exceptions\CounsellorRecognitionException;
use App\Models\CounsellorRecognition;
use App\Models\Request;
use Illuminate\Support\Facades\DB;

// TT-7.11d1/SCRUM-473: the counsellor answering a featuring consent request -- accepted or declined, written to the
// recognition with an event. Only the Counsellor it is addressed to gets here (EnsureUserCanRespondToRequestAction's
// `to` match; no administrator may answer it). Only an explicit answer counts (no response is not a decline here).
// A request whose recognition no longer waits on this answer is refused with a 422 and left as it is (like
// RespondToSafeguardingExceptionRequestAction's late answer), so nobody is recorded as having answered it.
//
// Lock order, as everywhere for recognitions: the recognition first, then its request.
class RespondToCounsellorRecognitionRequestAction extends Action
{
    public const NO_ANSWER = 'Choose to accept or decline being featured.';

    public const NO_LONGER_WAITING = 'This recognition is no longer waiting on your answer.';

    public function execute(RequestResponseDTO $requestResponseDTO)
    {
        $response = strtoupper((string) $requestResponseDTO->response);

        if (! in_array($response, [RequestStatusEnum::accepted->value, RequestStatusEnum::rejected->value], true)) {
            throw new CounsellorRecognitionException(self::NO_ANSWER, 422);
        }

        $accepted = $response === RequestStatusEnum::accepted->value;

        return DB::transaction(function () use ($requestResponseDTO, $accepted) {
            $forId = $requestResponseDTO->request->for_type === CounsellorRecognition::class ? $requestResponseDTO->request->for_id : null;
            $recognition = $forId ? CounsellorRecognition::query()->lockForUpdate()->find($forId) : null;
            $request = Request::query()->lockForUpdate()->findOrFail($requestResponseDTO->request->id);

            if ($request->status != RequestStatusEnum::pending->value
                || ! $recognition?->isApproved() || $recognition->consent !== Consent::pending) {
                throw new CounsellorRecognitionException(self::NO_LONGER_WAITING, 422);
            }

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
        }, 3);
    }
}
