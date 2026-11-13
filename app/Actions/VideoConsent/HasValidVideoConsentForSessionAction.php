<?php

namespace App\Actions\VideoConsent;

use App\Actions\Action;
use App\Enums\VideoConsentLevelEnum;
use App\Models\GroupTherapy;
use App\Models\Session;
use App\Models\Therapy;
use App\Models\User;
use App\Models\VideoConsent;

// TT-3.1e-d/SCRUM-283: the real, per-mode consent check that replaces the interim fail-closed
// block in EnsureVideoIsAvailableForSessionAction. Architect-specified shape (2026-09-11): ONE
// indexed existence query against video_consents, OR-ing across BOTH scope types (Therapy and
// Session) rather than two round-trips -- deliberately NOT dependent on reading
// Therapy.video_consent_mode first, which is what makes a later mode switch "prospective-only"
// correct for free (an old grant under the previous mode still satisfies this check). No N+1 risk
// regardless of guardian count: any one active guardian's grant suffices (SCRUM-288: only a
// guardian the ward still has), and there is at most one currently-valid row per ward and scope
// (GrantVideoConsentAction's own job to enforce; SCRUM-313: per ward, as a group has several).
//
// This is the hot join-path version of the same check IsVideoConsentOutstandingForSessionAction
// (TT-3.1e-e) makes for the low-frequency reminder sweep -- that action does two separate
// ->exists() calls, which is fine for a batch job but not for a per-join-attempt query; this one
// collapses both into a single query, per the architect's explicit requirement here.
class HasValidVideoConsentForSessionAction extends Action
{
    public function execute(Session $session): bool
    {
        // SCRUM-313 (security review): a group session's consent is each member's own (forGroupMember) -- never
        // "no ward, so nothing to consent to".
        if ($session->for_type === GroupTherapy::class) {
            return false;
        }

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

        // SCRUM-288 (security review): only a grant by a guardian who counts right now (Guardianship::
        // scopeActive -- still their guardian, account not deleted or suspended, adult). Checked at
        // read time, so a grant by a suspended guardian applies again once the suspension ends.
        return VideoConsent::query()
            ->whereIn('guardian_id', $ward->activeGuardianships()->select('guardianship.guardian_id'))
            ->where(function ($query) use ($therapy, $session, $ward) {
                $query
                    ->where(fn ($q) => $q->whereValidFor($ward->id, Therapy::class, $therapy->id))
                    ->orWhere(fn ($q) => $q->whereValidFor($ward->id, Session::class, $session->id));
            })
            ->exists();
    }

    // SCRUM-313: whether $ward may be in this group session's call by their guardians' consent -- a valid grant, at
    // any level, for the whole group or for this session, from a guardian they still have. The same shape as the
    // one-to-one check above, per ward: a group's minors each have their own.
    public function forGroupMember(Session $session, User $ward): bool
    {
        if (! $session->for instanceof GroupTherapy) {
            return false;
        }

        return $this->groupGrants($session, $ward)->exists();
    }

    // SCRUM-329: whether $ward's guardians have consented to them speaking, not just watching, in this group session --
    // a valid SPEAK-level grant for the group or the session, from a guardian they still have.
    public function speakingAllowedFor(Session $session, User $ward): bool
    {
        return $session->for instanceof GroupTherapy && $this->groupGrants($session, $ward)
            ->where('level', VideoConsentLevelEnum::speak->value)
            ->exists();
    }

    private function groupGrants(Session $session, User $ward)
    {
        return VideoConsent::query()
            ->fromActiveGuardianOf($ward)
            ->where(function ($query) use ($session, $ward) {
                $query
                    ->where(fn ($q) => $q->whereValidFor($ward->id, GroupTherapy::class, $session->for_id))
                    ->orWhere(fn ($q) => $q->whereValidFor($ward->id, Session::class, $session->id));
            });
    }
}
