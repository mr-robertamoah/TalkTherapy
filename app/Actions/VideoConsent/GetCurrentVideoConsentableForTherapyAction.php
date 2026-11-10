<?php

namespace App\Actions\VideoConsent;

use App\Actions\Action;
use App\Enums\SessionStatusEnum;
use App\Enums\VideoConsentModeEnum;
use App\Models\Session;
use App\Models\Therapy;

// TT-3.1e-f/SCRUM-285: "what should the guardian's approve/revoke button on the therapy page act
// on right now" -- PER_THERAPY mode always targets the Therapy itself. PER_SESSION mode targets
// the SOONEST relevant session (currently in progress, or the next still-pending one) rather than
// Session::getActiveSessionAttribute()'s narrower "in progress or within 5 minutes of starting"
// definition -- that definition is too narrow for this UI's purpose: e-e's own reminder emails
// fire a full day before a session starts, well outside that window, so a guardian acting on a
// reminder must still find a real, actionable target here. Returns null only when the therapy has
// no session at all to act on yet (PER_SESSION mode, nothing scheduled) -- the caller decides how
// to render that ("no session currently needs consent").
class GetCurrentVideoConsentableForTherapyAction extends Action
{
    public function execute(Therapy $therapy): Therapy|Session|null
    {
        if ($therapy->video_consent_mode !== VideoConsentModeEnum::per_session->value) {
            return $therapy;
        }

        return Session::query()
            ->whereTherapyId($therapy->id)
            ->whereIn('status', [
                SessionStatusEnum::pending->value,
                SessionStatusEnum::in_session->value,
                SessionStatusEnum::in_session_confirmation->value,
            ])
            ->orderBy('start_time')
            ->first();
    }
}
