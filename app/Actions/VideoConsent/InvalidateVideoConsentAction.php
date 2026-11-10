<?php

namespace App\Actions\VideoConsent;

use App\Actions\Action;
use App\Actions\Video\EndVideoSessionAction;
use App\Enums\SessionStatusEnum;
use App\Enums\VideoConsentRevocationReasonEnum;
use App\Models\Session;
use App\Models\User;
use App\Models\VideoConsent;
use Illuminate\Support\Collection;

// TT-3.1e-c/SCRUM-282: the ONE shared invalidation path both RevokeVideoConsentAction (a
// guardian's own deliberate revoke) and DeleteGuardianshipAction's cascade (a system-triggered
// lapse) go through -- no authorization check of its own, since the two callers have
// fundamentally different authorization shapes (a guardian acting on their own behalf, vs. a
// guardianship deletion that already happened and has no "acting guardian" to check). Callers are
// responsible for authorizing before calling this.
//
// Idempotent: revoking an already-revoked grant is a no-op that returns the row unchanged, rather
// than re-stamping revoked_at/reason or re-running the (idempotent-anyway, but pointless)
// call-termination sweep a second time.
//
// Termination is synchronous, not a lazily-checked flag -- EndVideoSessionAction runs inline,
// in-request, before this action returns, per the ticket's own explicit "not a flag checked on
// next join" requirement.
class InvalidateVideoConsentAction extends Action
{
    public function execute(VideoConsent $consent, VideoConsentRevocationReasonEnum $reason, ?User $revokedByGuardian = null): VideoConsent
    {
        if (! $consent->isValid()) {
            return $consent;
        }

        $consent->update([
            'revoked_at' => now(),
            'revoked_by_guardian_id' => $revokedByGuardian?->id,
            'revocation_reason' => $reason->value,
        ]);

        $this->activeSessionsFor($consent)->each(
            fn (Session $session) => EndVideoSessionAction::new()->execute($session)
        );

        return $consent;
    }

    private function activeSessionsFor(VideoConsent $consent): Collection
    {
        // Reviewer finding (2026-09-11): a Session past its scheduled end_time can legally be
        // soft-deleted (Session::isNotDeleteable() only blocks the "about to start"/"between
        // start and end" windows, never checks status or wherePastEndTime()) while still
        // in_session with an active VideoSession -- a call that overran its schedule and was
        // never explicitly ended. The default morphTo()/query excludes trashed rows, so relying
        // on $consent->consentable (a PER_SESSION consent's cached relation) crashed with a
        // TypeError on a null Session AFTER revoked_at had already committed -- consent marked
        // revoked, live call left running, the exact failure this ticket exists to prevent.
        // Re-querying withTrashed() by id, rather than trusting the relation, fixes both this
        // crash and the mirror-image silent skip on the PER_THERAPY branch, and also removes any
        // dependency on whether the relation happened to be freshly loaded.
        $sessions = $consent->consentable_type === Session::class
            ? Session::withTrashed()->where('id', $consent->consentable_id)->get()
            : Session::withTrashed()->whereTherapyId($consent->consentable_id)->get();

        return $sessions->filter(fn (Session $session) => in_array($session->status, [
            SessionStatusEnum::in_session->value,
            SessionStatusEnum::in_session_confirmation->value,
        ]));
    }
}
