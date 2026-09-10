<?php

namespace App\Http\Resources;

use App\Actions\Organization\GetRetainerCoveringOrganizationAction;
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
