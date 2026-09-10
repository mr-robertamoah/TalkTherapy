<?php

namespace App\Http\Resources;

use App\Enums\ConstantsEnum;
use App\Enums\TherapyPaymentTypeEnum;
use App\Enums\TherapyPerPaymentEnum;
use App\Models\Counsellor;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GroupTherapyResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $activeSession = null;
        $activeDiscussion = null;
        $user = $request->user();

        if ($user && $this->isParticipant($user)) {
            $activeSession = $this->getActiveSession($user);
        }

        if ($user?->counsellor) {
            $activeDiscussion = $this->getActiveDiscussion($user->counsellor);
        }

        $isAnonymous = $this->addedByUserIsMaskedFor($user);

        // TT-7.4d-a/SCRUM-258: `paymentStatus` below is "the group's latest transaction by ANY
        // member" -- correct for TT-7.4c's existing counsellor-facing indicator (which cares
        // whether the group has at least one paid transaction on record), but meaningless as an
        // answer to "have I, the viewer, paid my own share" once more than one member can have
        // their own Transaction row. Scoped to the viewer specifically, via the same
        // latestTransactionFor() method SessionResource now also uses -- only queried for a PAID,
        // PER_THERAPY group (reviewer finding: mirrors TherapyResource's own identical guard) --
        // a PER_SESSION group's transactions live on its Sessions, not here, so this query would
        // just return empty for one; guarding it out entirely avoids the wasted round-trip, same
        // as the FREE-case guard already does.
        $viewerTransaction = $this->payment_type === TherapyPaymentTypeEnum::paid->value
            && ($this->payment_data['per'] ?? null) === TherapyPerPaymentEnum::therapy->value
            && $user
            ? $this->latestTransactionFor($user)
            : null;

        return [
            'id' => $this->id,
            'name' => $this->name,
            // Recognizes pivot-attached members too (SCRUM-69/SCRUM-72), not just the creator
            // or an assigned counsellor -- lets the frontend hide the "join" action for an
            // already-joined member.
            'isParticipant' => $user ? $this->isParticipant($user) : false,
            'addedby' => $this->addedby_type == Counsellor::class
                ? new CounsellorMiniResource($this->addedby)
                : $this->when(
                    ! $isAnonymous,
                    new UserMiniResource($this->addedby),
                    ['id' => $this->addedby?->id, 'fullName' => ConstantsEnum::anonymousUserLabel->value]
                ),
            'public' => (bool) $this->public,
            'anonymous' => (bool) $this->anonymous,
            'allowInPerson' => (bool) $this->allow_in_person,
            'allowAnyone' => (bool) $this->allow_anyone,
            'counsellors' => CounsellorMiniResource::collection($this->counsellors),
            'about' => $this->about,
            'sessionsHeld' => $this->sessionsHeld,
            'status' => $this->getStatus(),
            'paymentData' => $this->payment_data,
            'paymentStatus' => $this->latestTransaction?->status,
            // TT-7.4d-a/SCRUM-258: viewer-scoped counterparts to the model-wide `paymentStatus`
            // above, additive so TT-7.4c's existing counsellor consumer of `paymentStatus` is
            // unaffected -- mirrors TherapyResource/SessionResource's own `transactionId`/
            // `refundRequestStatus` naming exactly. `viewerPaymentStatus` is the one genuinely new
            // concept: TherapyResource never needed it, since an individual Therapy's single-payer
            // model means its own `paymentStatus` already IS the viewer's own status.
            'viewerPaymentStatus' => $viewerTransaction?->status,
            'transactionId' => $viewerTransaction?->id,
            'refundRequestStatus' => $viewerTransaction?->latestRefundRequest?->status,
            'sessionsCreated' => $this->sessionsCreated,
            'paymentType' => $this->payment_type,
            'sessionType' => $this->session_type,
            'paidSessions' => $this->paidSessions,
            'freeSessions' => $this->freeSessions,
            'cases' => TherapyCaseResource::collection($this->cases),
            'maxSessions' => $this->max_sessions,
            'maxUsers' => $this->max_users,
            'maxCounsellors' => $this->max_counsellors,
            'topicsCount' => $this->topicsCount,
            'createdAt' => $this->created_at,
            'activeSession' => $activeSession ? new SessionResource($activeSession) : null,
            'activeDiscussion' => $activeDiscussion ? new DiscussionResource($activeDiscussion) : null,
        ];
    }
}
