<?php

namespace App\Actions\VideoConsent;

use App\Actions\Action;
use App\Models\Session;
use App\Models\Therapy;
use App\Models\VideoConsent;

// TT-3.1e-e/SCRUM-284: "is a reminder for this session still warranted" -- mirrors what e-d's own
// future enforcement query will check (an OR across BOTH scope types, regardless of the therapy's
// CURRENT video_consent_mode), not just whichever mode happens to be set right now. Consistency
// with that matters here: a session whose OWN PER_SESSION grant already exists must stop getting
// reminders even if the therapy later switches to PER_THERAPY mode without a fresh grant --
// "mode switches are prospective-only" (TT-3.1e-a) means that old grant will still satisfy e-d's
// join check, so reminding about it would be actively wrong, not just unnecessary.
//
// Returns false (nothing outstanding, no reminder needed) for a session with no minor ward at all
// (an adult client, or a GroupTherapy session) -- there's no guardian consent concept to satisfy.
class IsVideoConsentOutstandingForSessionAction extends Action
{
    public function execute(Session $session): bool
    {
        $wardResolver = GetWardForVideoConsentableAction::new();
        $ward = $wardResolver->execute($session);

        // An adult client has no guardian-consent concept to satisfy at all, so nothing is ever
        // outstanding for them.
        //
        // TT-4.10b/SCRUM-291: "adult client" was a live $ward->isAdult() re-check -- now prefers
        // the therapy's own stable client_was_minor_at_creation snapshot (TT-4.10a) via
        // GetWardForVideoConsentableAction::isMinor(), closing the self-editable-dob bypass
        // SCRUM-287 found.
        if (! $ward || ! $wardResolver->isMinor($session)) {
            return false;
        }

        $therapy = $wardResolver->therapyFor($session);

        $hasValidSessionGrant = VideoConsent::query()
            ->whereValidFor(Session::class, $session->id)
            ->exists();

        $hasValidTherapyGrant = VideoConsent::query()
            ->whereValidFor(Therapy::class, $therapy->id)
            ->exists();

        return ! $hasValidSessionGrant && ! $hasValidTherapyGrant;
    }
}
