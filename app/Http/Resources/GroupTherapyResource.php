<?php

namespace App\Http\Resources;

use App\Actions\GroupTherapy\GetGroupTherapyPaymentRosterAction;
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
        $isPaidPerTherapyGroup = $this->payment_type === TherapyPaymentTypeEnum::paid->value
            && ($this->payment_data['per'] ?? null) === TherapyPerPaymentEnum::therapy->value;

        $viewerTransaction = $isPaidPerTherapyGroup && $user
            ? $this->latestTransactionFor($user)
            : null;

        // TT-7.4d-d/SCRUM-261: the counsellor-facing per-member payment roster -- a deliberate,
        // scoped exception to this codebase's otherwise-universal anonymity rule
        // (TherapyTrait::addedByUserIsMaskedFor(), RequestResource, MessageResource all mask
        // identity from the counsellor too). Only THIS group's own counsellor (not any counsellor
        // anywhere) ever receives it, and only real identity for payment reconciliation -- nothing
        // else about an anonymous group becomes de-anonymized. PER_SESSION excluded, same reasoning
        // as $viewerTransaction above: a PER_SESSION group's payment status lives per-session, not
        // per-group, so a single flat per-member roster row can't represent it correctly.
        $isRosterEligibleCounsellor = $isPaidPerTherapyGroup
            && $user?->counsellor
            && $this->isCounsellor($user->counsellor);

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
            // TT-7.4d-c/SCRUM-260: viewer-scoped counterpart to TherapyResource's own unscoped
            // `refundStatus` -- safe unscoped there (one payer), but this resource is multi-payer,
            // so it's derived from the same $viewerTransaction as viewerPaymentStatus/transactionId
            // above rather than the group-wide `latestTransaction`. Needed once TT-7.4d-c enables
            // real refund requests for group members: without it, a member whose refund succeeds
            // would keep seeing "Paid" forever (paymentStatus/viewerPaymentStatus never flip off
            // SUCCESS on refund -- see TT-7.7a's decision-log entry).
            'refundStatus' => $viewerTransaction?->successfulRefund?->status,
            'transactionId' => $viewerTransaction?->id,
            'refundRequestStatus' => $viewerTransaction?->latestRefundRequest?->status,
            // TT-7.4d-d/SCRUM-261
            'paymentRoster' => $this->when(
                $isRosterEligibleCounsellor,
                fn () => GetGroupTherapyPaymentRosterAction::new()->execute($this->resource)
            ),
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
