<?php

namespace App\Http\Resources;

use App\Models\Counsellor;
use App\Models\Therapy;
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

        return [
            'id' => $this->id,
            'userId' => $this->addedby_type == Counsellor::class ? $this->addedby->user->id : $this->addedby_id,
            'updatedById' => $this->updatedby_type == Counsellor::class ? $this->updatedby->user_id : $this->updatedby_id,
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
            'paymentStatus' => $this->latestTransaction?->status,
            // TT-7.7e/SCRUM-253 (security-engineer finding, MEDIUM): unlike TherapyResource's own
            // identical-looking field -- safe unscoped there, since an individual Therapy only
            // ever has one payer -- `latestTransaction` here is shared with GroupTherapy sessions,
            // where it can belong to a DIFFERENT member entirely (see this file's own comment on
            // `viewerTransaction` above). Exposing refundStatus unconditionally would leak one
            // member's refund outcome -- a materially more sensitive, dispute-flavored fact than a
            // generic paymentStatus -- to every other member and the counsellor. Refunds are
            // individual-Therapy-only for this entire epic (no ask/admin-queue path exists for a
            // GroupTherapy session), so this is simply withheld entirely for one, rather than
            // attempting `viewerTransaction`-scoping (which would also hide it from the
            // counsellor -- the exact opposite of this ticket's own point).
            'refundStatus' => $isIndividualTherapy ? $this->latestTransaction?->successfulRefund?->status : null,
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
}
