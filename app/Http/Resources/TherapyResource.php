<?php

namespace App\Http\Resources;

use App\Actions\Organization\GetRetainerCoveringOrganizationAction;
use App\Actions\VideoConsent\GetCurrentValidVideoConsentForTherapyAction;
use App\Actions\VideoConsent\GetWardForVideoConsentableAction;
use App\Enums\ConstantsEnum;
use App\Enums\TherapyPaymentTypeEnum;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TherapyResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // TODO load up active session and discussion
        $activeSession = null;
        $activeDiscussion = null;
        $user = $request->user();
        $counsellor = $this->counsellor()->withTrashed()->first();

        if ($user && $this->isParticipant($user)) {
            $activeSession = $this->getActiveSession($user);
        }

        if ($user?->counsellor) {
            $activeDiscussion = $this->getActiveDiscussion($user->counsellor);
        }

        // TT-7.7b/SCRUM-250 (security-engineer finding, HIGH -- SessionResource's own identical
        // fix): `latestTransaction` is "latest across ALL eligible payers", not scoped to the
        // current viewer. Scoped here to the viewer's own transaction so a co-participant (or the
        // assigned counsellor, never the payer) can never see another client's transactionId or
        // refund/dispute status. Only queried for a PAID, PER_THERAPY engagement -- guarded BEFORE
        // running the query, mirroring orgRetainerCoverage()'s own early-return.
        $viewerTransaction = $user && $this->payment_type === TherapyPaymentTypeEnum::paid->value && ($this->payment_data['per'] ?? null) === 'PER_THERAPY'
            ? $this->transactions()->where('user_id', $user->id)->latest('created_at')->first()
            : null;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'user' => $this->when(
                ! $this->addedByUserIsMaskedFor($user),
                new UserMiniResource($this->addedby),
                ['id' => $this->addedby?->id, 'fullName' => ConstantsEnum::anonymousUserLabel->value]
            ),
            'public' => (bool) $this->public,
            'anonymous' => (bool) $this->anonymous,
            'allowInPerson' => (bool) $this->allow_in_person,
            'counsellor' => $this->when($counsellor, new CounsellorMiniResource($counsellor)),
            'backgroundStory' => $this->background_story,
            'sessionsHeld' => $this->sessionsHeld,
            'status' => $this->getStatus(),
            'paymentData' => $this->payment_data,
            'paymentStatus' => $this->latestTransaction?->status,
            // TT-7.7e/SCRUM-253: unlike refundRequestStatus below (deliberately viewer-scoped --
            // it discloses a specific client's own pending/rejected ask), "has this transaction
            // actually been refunded" is a coarse, non-identifying fact about the same
            // latestTransaction paymentStatus already exposes to every viewer above -- so it's
            // exposed the same, unscoped way. Safe specifically BECAUSE an individual Therapy has
            // exactly one payer -- GroupTherapy (multiple payers) uses its own separate
            // GroupTherapyResource, not this one, and deliberately does NOT get this field (see
            // SessionResource's identical field for why its own shared, multi-payer-capable
            // version of this exact field needs the opposite treatment). Once set, the
            // client/counsellor UI shows "Refunded" instead of a now-stale "Paid" (paymentStatus
            // itself never changes on refund -- see
            // TT-7.7a's own decision-log entry on why refunds live in their own table).
            'refundStatus' => $this->latestTransaction?->successfulRefund?->status,
            // TT-7.7b/SCRUM-250
            'transactionId' => $viewerTransaction?->id,
            'refundRequestStatus' => $viewerTransaction?->latestRefundRequest?->status,
            'sessionsCreated' => $this->sessionsCreated,
            'paymentType' => $this->payment_type,
            'sessionType' => $this->session_type,
            'paidSessions' => $this->paidSessions,
            'freeSessions' => $this->freeSessions,
            'cases' => TherapyCaseResource::collection($this->cases),
            'maxSessions' => $this->max_sessions,
            'topicsCount' => $this->topicsCount,
            'createdAt' => $this->created_at,
            'activeSession' => $activeSession ? new SessionResource($activeSession) : null,
            'activeDiscussion' => $activeDiscussion ? new DiscussionResource($activeDiscussion) : null,
            'orgRetainerCoverage' => $this->orgRetainerCoverage($user),
            'videoConsent' => $this->videoConsentData($user),
        ];
    }

    // TT-3.1e-f/SCRUM-285: null (the whole section doesn't apply) unless this therapy actually
    // has a minor client -- avoids the ward/guardian/consent lookups below on every ordinary
    // (adult-client) therapy page load. `viewerIsGuardian` is display-only, matching
    // computedIsCounsellor's own existing role elsewhere on this page -- the real authorization
    // boundary is always the backend Action itself (GrantVideoConsentAction/
    // RevokeVideoConsentAction/SetVideoConsentModeAction), not this flag.
    //
    // Security-review finding (2026-09-11, HIGH): a `public` Therapy is reachable by ANY
    // visitor, including a guest, before this method ran at all -- EnsureUserHasAccessToTherapyAction
    // explicitly returns for `$therapy->public` before even checking whether $user exists. Unlike
    // this resource's own 'user'/orgRetainerCoverage fields (both gated behind
    // addedByUserIsMaskedFor()), this method had NO gate of its own, so it disclosed that a
    // public therapy's client is a minor, the therapy's consent mode, whether consent is
    // currently valid, and the granting/revoking guardian's real name, to a completely
    // unauthenticated/unrelated visitor. Gated the same way every other guardian-facing surface
    // in this feature already is: the viewer must actually be a participant (client or
    // counsellor) OR a guardian of this specific ward.
    private function videoConsentData(?User $user): ?array
    {
        // Resolved before the participant/guardian gate below (not $this->addedby directly) --
        // GetWardForVideoConsentableAction already safely returns null for a non-User addedby
        // (e.g. an org-owned Therapy), where calling User::isGuardianOf() on a non-User value
        // would otherwise be a type error.
        $wardResolver = GetWardForVideoConsentableAction::new();
        $ward = $wardResolver->execute($this->resource);

        // TT-4.10b/SCRUM-291: "adult client" was a live $ward->isAdult() re-check -- now prefers
        // the therapy's own stable client_was_minor_at_creation snapshot (TT-4.10a) via
        // GetWardForVideoConsentableAction::isMinor(), closing the self-editable-dob bypass
        // SCRUM-287 found.
        if (! $ward || ! $wardResolver->isMinor($this->resource)) {
            return null;
        }

        if (! $user || ! ($this->isParticipant($user) || $user->isGuardianOf($ward))) {
            return null;
        }

        $currentConsent = GetCurrentValidVideoConsentForTherapyAction::new()->execute($this->resource);

        return [
            'mode' => $this->video_consent_mode,
            'viewerIsGuardian' => (bool) ($user && $user->isGuardianOf($ward)),
            'current' => $currentConsent ? new VideoConsentResource($currentConsent) : null,
        ];
    }

    // TT-7.3b-k/SCRUM-242: a non-financial disclosure for the paying client (never scoped to the
    // currently viewing user beyond the anonymity check below -- the fact that itself isn't
    // sensitive, so it's fine to also surface it to the counsellor). Only the org's name is
    // exposed, never fee/compensation/payout figures, matching this ticket's deliberately narrow
    // client-facing-disclosure scope. Applies to Therapy only, not GroupTherapy -- retainer
    // coverage checks a therapy's single counsellor, and GroupTherapy org billing is out of scope
    // here the same way TT-7.5a's strict-gate feature excluded it.
    private function orgRetainerCoverage(?User $user): ?array
    {
        if (! $this->addedby instanceof User) {
            return null;
        }

        // Same anonymity guarantee as the 'user' field above (security-engineer finding,
        // SCRUM-242 review): naming the covering org to a non-participant on a public+anonymous
        // therapy would re-identify the addedby, narrowing down who they are.
        if ($this->addedByUserIsMaskedFor($user)) {
            return null;
        }

        // Skip the query entirely for a therapy the frontend could never show a Pay control for
        // in the first place -- avoids a join-heavy lookup on every FREE-therapy page load.
        if ($this->payment_type !== TherapyPaymentTypeEnum::paid->value) {
            return null;
        }

        $organization = GetRetainerCoveringOrganizationAction::new()->execute($this->resource, $this->addedby);

        return $organization ? ['organizationName' => $organization->name] : null;
    }
}
