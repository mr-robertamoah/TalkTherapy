<?php

namespace App\Actions\VideoConsent;

use App\Actions\Action;
use App\Models\Session;
use App\Models\Therapy;
use App\Models\VideoConsent;

// TT-3.1e-d/SCRUM-283: the real, per-mode consent check that replaces the interim fail-closed
// block in EnsureVideoIsAvailableForSessionAction. Architect-specified shape (2026-09-11): ONE
// indexed existence query against video_consents, OR-ing across BOTH scope types (Therapy and
// Session) rather than two round-trips -- deliberately NOT dependent on reading
// Therapy.video_consent_mode first, which is what makes a later mode switch "prospective-only"
// correct for free (an old grant under the previous mode still satisfies this check). No N+1 risk
// regardless of guardian count -- the query doesn't filter by guardian at all, since "any one
// guardian's grant suffices" is already baked into there being at most one currently-valid row
// per scope (GrantVideoConsentAction's own job to enforce).
//
// This is the hot join-path version of the same check IsVideoConsentOutstandingForSessionAction
// (TT-3.1e-e) makes for the low-frequency reminder sweep -- that action does two separate
// ->exists() calls, which is fine for a batch job but not for a per-join-attempt query; this one
// collapses both into a single query, per the architect's explicit requirement here.
class HasValidVideoConsentForSessionAction extends Action
{
    public function execute(Session $session): bool
    {
        $wardResolver = GetWardForVideoConsentableAction::new();
        $ward = $wardResolver->execute($session);

        // No ward concept for this session at all (e.g. an org-owned Therapy, or a session whose
        // therapy has no resolvable User addedby), or an adult client -- nothing to gate on, so
        // consent is trivially satisfied. Checked here too as defense-in-depth, even though the
        // current caller (EnsureVideoIsAvailableForSessionAction) already only reaches this
        // action for a non-adult, non-counsellor joiner.
        //
        // TT-4.10b/SCRUM-291: "adult client" was a live $ward->isAdult() re-check -- now prefers
        // the therapy's own stable client_was_minor_at_creation snapshot (TT-4.10a) via
        // GetWardForVideoConsentableAction::isMinor(), closing the self-editable-dob bypass
        // SCRUM-287 found.
        if (! $ward || ! $wardResolver->isMinor($session)) {
            return true;
        }

        $therapy = $wardResolver->therapyFor($session);

        return VideoConsent::query()
            ->where('ward_id', $ward->id)
            ->where(function ($query) use ($therapy, $session) {
                $query
                    ->where(fn ($q) => $q->whereValidFor(Therapy::class, $therapy->id))
                    ->orWhere(fn ($q) => $q->whereValidFor(Session::class, $session->id));
            })
            ->exists();
    }
}
