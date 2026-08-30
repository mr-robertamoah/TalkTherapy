<?php

namespace App\Actions\Request;

use App\Actions\Action;
use App\DTOs\RequestResponseDTO;
use App\Enums\RequestStatusEnum;
use App\Exceptions\BadRequestException;

class EnsureRequestIsStillPendingAction extends Action
{
    // SCRUM-171: corrects a *response-honesty* gap, not a data-integrity one -- every
    // RespondTo*RequestAction already re-checks the request's status under a DB lock and
    // silently no-ops (returning the request unchanged) if it's no longer PENDING (SCRUM-80/91),
    // so an already-decided request's status was never actually at risk of being overwritten.
    // What was missing is that RequestController::respond() still reported that silent no-op as
    // a 201 success, giving a caller (a second, slower responder; a stale browser tab; a
    // double-click) no way to tell their response did nothing. This check runs before any write
    // in RequestService::respondToRequest() so that case surfaces as a clean 422 instead --
    // decided deliberately as a behavior change to the shared respond pipeline, not merely a bug
    // fix (see the 2026-08-30 decision-log entry for SCRUM-171).
    //
    // This is a fast, unlocked pre-check, not the actual concurrency guarantee -- under a truly
    // simultaneous race, both callers can still pass this check before either commits; the
    // *data* stays correct either way because of the lock each RespondTo*RequestAction already
    // holds, but the loser of that race gets a 201 (its own silent no-op) rather than this 422.
    // Closing that narrow window would mean moving this check inside each of those 9 actions'
    // existing locked blocks instead -- out of scope here, tracked as a possible follow-up.
    public function execute(RequestResponseDTO $requestResponseDTO)
    {
        if ($requestResponseDTO->request->status === RequestStatusEnum::pending->value) {
            return;
        }

        throw new BadRequestException('This request is no longer pending and can no longer be responded to.', 422);
    }
}
