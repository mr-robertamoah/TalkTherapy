<?php

namespace App\Http\Resources;

use App\Models\Counsellor;
use App\Models\Therapy;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SessionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $currentTopic = $this->currentTopic;
        $isIndividualTherapy = $this->for_type === Therapy::class;
        // TT-7.7b/SCRUM-250 (security-engineer finding, HIGH): `latestTransaction` is "latest
        // across ALL eligible payers, not scoped to the current viewer" (see its own comment on
        // Session/TherapyTrait) -- for a GroupTherapy session with several members, exposing ITS
        // transactionId/refundRequestStatus unconditionally would leak a co-participant's own
        // transaction identity and refund/dispute status to every other member. Scoped here to
        // the transaction actually belonging to the viewer (also correctly resolves to null for
        // the assigned counsellor, who is never the payer).
        //
        // Only queried for a PAID session -- guarded BEFORE running the query, not just before
        // reading its result, so the common FREE case (e.g. the counsellor calendar's own N+1
        // regression test) never pays for it at all.
        //
        // TT-7.4d-a/SCRUM-258: this used to be its own inline query -- promoted to
        // Session::latestTransactionFor(), the same method GroupTherapyResource/TT-7.4d-d's
        // roster now share, instead of three near-identical copies of "this user's own latest
        // transaction for this payable."
        $viewerTransaction = $this->payment_type === 'PAID' && $request->user()
            ? $this->latestTransactionFor($request->user())
            : null;
        // SCRUM-263/368: the session's own payment status (whoever paid) only for those it is about --
        // see Session::showsPaymentStatusTo().
        // Only asked when there is a status to show (most sessions are free, with no transaction).
        $showsPaymentStatus = $this->latestTransaction && $this->showsPaymentStatusTo($request->user());

        return [
            'id' => $this->id,
            'userId' => $this->addedby_type == Counsellor::class ? $this->addedby->user->id : $this->maskedUserId($this->addedby, $request->user()),
            'updatedById' => $this->updatedby_type == Counsellor::class ? $this->updatedby->user_id : $this->maskedUserId($this->updatedby, $request->user(), (bool) $this->updatedby_anonymous),
            'name' => $this->name,
            'about' => $this->about,
            'type' => $this->type,
            'lng' => $this->longitude,
            'lat' => $this->latitude,
            'status' => $this->status,
            'topics' => TherapyTopicMiniResource::collection($this->topics),
            'currentTopic' => $currentTopic ? new TherapyTopicMiniResource($currentTopic) : null,
            'cases' => TherapyCaseResource::collection($this->cases),
            'startTime' => $this->start_time,
            'endTime' => $this->end_time,
            'paymentType' => $this->payment_type,
            // SCRUM-263/368: null for anyone the payment isn't about -- for a group, anyone but its
            // counsellors and administrators (members use viewerPaymentStatus); for an individual
            // therapy, anyone but the client, the counsellor and administrators.
            'paymentStatus' => $showsPaymentStatus ? $this->latestTransaction?->status : null,
            // TT-7.4d-b/SCRUM-259: `paymentStatus` above is unscoped (see `viewerTransaction`'s own
            // comment) -- safe as the sole status field for an individual-Therapy session (exactly
            // one payer, so it already reads as "did I pay"), but wrong for a GroupTherapy session
            // once each member can have their own Transaction row. Additive, mirrors
            // GroupTherapyResource's identical field: usePayment.js's canPayForSession() switches to
            // this one for a group so a member's own Pay control reflects THEIR OWN payment, not
            // whichever member paid first.
            'viewerPaymentStatus' => $viewerTransaction?->status,
            // TT-7.7e/SCRUM-253 (security-engineer finding, MEDIUM): unlike TherapyResource's own
            // identical-looking field -- safe unscoped there, since an individual Therapy only
            // ever has one payer -- `latestTransaction` here is shared with GroupTherapy sessions,
            // where it can belong to a DIFFERENT member entirely (see this file's own comment on
            // `viewerTransaction` above). Exposing refundStatus unconditionally would leak one
            // member's refund outcome -- a materially more sensitive, dispute-flavored fact than a
            // generic paymentStatus -- to every other member and the counsellor.
            //
            // TT-7.4d-c/SCRUM-260: refund requests are no longer individual-Therapy-only (this
            // ticket enables the ask for group members too, since RequestRefundAction was already
            // transaction/user-scoped, not model-scoped) -- so a GroupTherapy session now gets
            // viewer-scoped refundStatus via the same $viewerTransaction as viewerPaymentStatus/
            // transactionId above, rather than withholding it entirely. Still correctly resolves to
            // null for the counsellor (never the payer, never has a $viewerTransaction) and for a
            // co-member who isn't the one who was refunded.
            'refundStatus' => $isIndividualTherapy
                ? ($showsPaymentStatus ? $this->latestTransaction?->successfulRefund?->status : null)
                : $viewerTransaction?->successfulRefund?->status,
            // TT-7.7b/SCRUM-250
            'transactionId' => $viewerTransaction?->id,
            'refundRequestStatus' => $viewerTransaction?->latestRefundRequest?->status,
            'landmark' => $this->landmark,
            'isSession' => true,
            'createdAt' => $this->created_at,
            // SCRUM-212: only present when the caller eager-loaded `for` -- the counsellor
            // calendar aggregate is the first consumer that needs to know which Therapy/
            // GroupTherapy a session belongs to; every other existing call site already knows its
            // one parent from context and never eager-loads this, so this stays a MissingValue
            // (omitted) for them.
            'for' => $this->whenLoaded('for', fn () => $isIndividualTherapy
                ? new TherapyMiniResource($this->for)
                : new GroupTherapyMiniResource($this->for)),
            // SCRUM-213/TT-2.6b: neither mini resource above exposes an explicit discriminator,
            // and the calendar UI needs one to route a click through to the right page
            // (therapies.get vs group.therapies.get) without inferring it from field presence.
            'forType' => $this->whenLoaded('for', fn () => $isIndividualTherapy ? 'individual' : 'group'),
        ];
    }

    // SCRUM-345 (security review): a client who adds or confirms a session in an anonymous therapy
    // is recorded as its addedby/updatedby -- their real id went to every other member. The frontend
    // only compares these with the viewer's own id, and a masked person is never the viewer.
    // SCRUM-73: $snapshot -- they were masked when they did it (sessions.updatedby_anonymous), so a
    // member who rejoins as named isn't unmasked on what they did while anonymous. (A group session's
    // addedby is always a counsellor or an administrator, so only updatedby has one.)
    private function maskedUserId(?User $user, ?User $viewer, bool $snapshot = false): ?int
    {
        if (! $user) {
            return null;
        }

        // SCRUM-380: TherapyTrait::hidesIdentityOf() via Session::hidesIdentityOf().
        return ($snapshot || $this->hidesIdentityOf($user)) && ! $user->is($viewer) ? null : $user->id;
    }
}
