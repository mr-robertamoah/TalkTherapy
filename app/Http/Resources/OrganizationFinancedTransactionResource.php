<?php

namespace App\Http\Resources;

use App\Actions\Transaction\ResolveTransactionSubjectAction;
use App\Models\CounsellorEarning;
use App\Models\GroupTherapy;
use App\Models\Therapy;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

// TT-7.3b-j/SCRUM-241: one row per pay-per-use financed Transaction. Deliberately not the full
// SessionResource/TherapyResource for the subject -- an org admin's reconciliation view has no
// legitimate need for clinical content, only "which engagement, which counsellor, how much" (the
// same data-minimization call already made for OrganizationMemberResource, SCRUM-159).
//
// Security-engineer finding: `subjectLabel` is deliberately an opaque "Therapy #<id>" reference,
// NEVER the therapy's own `name` -- that field is client-authored free text (typed in the same
// form step as background_story), not a system-generated label, and this org-billing admin is a
// genuinely new category of viewer with no other route to ever see therapy content at all (unlike
// the counsellor/client/admin-with-full-access viewers TherapyResource/SessionResource already
// serve `name` to). Showing it verbatim here -- especially for an `anonymous`-flagged therapy --
// would be exactly the identity/condition-revealing leak TherapyResource's own orgRetainerCoverage()
// masking (SCRUM-242) exists to prevent, just in the opposite direction.
class OrganizationFinancedTransactionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $subject = ResolveTransactionSubjectAction::new()->execute($this->for);
        // Reviewer finding: safe to take the first (and only) earning here without further checks
        // -- a settlement Transaction (for_type = OrganizationInvoice, which DOES fan out into
        // many earnings) is already excluded from this list entirely by
        // GetOrganizationFinancedTransactionsAction's own query; every remaining subject type
        // generates at most one earning (GenerateCounsellorEarningsAction: exactly one for an
        // org-financed Therapy, none at all for an org-financed GroupTherapy, since org billing
        // was never built for group therapies -- see the comment on `counsellorName` below).
        $earning = $this->whenLoaded('earnings', fn () => $this->earnings->first());

        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'subjectType' => $subject instanceof GroupTherapy ? 'GroupTherapy' : ($subject instanceof Therapy ? 'Therapy' : null),
            'subjectLabel' => $subject instanceof GroupTherapy
                ? "Group Therapy #{$subject->id}"
                : ($subject instanceof Therapy ? "Therapy #{$subject->id}" : null),
            // The counsellor's own name is professional/public information (already shown
            // throughout this org's existing dashboard, e.g. CounsellorsSection.vue) -- unlike the
            // client-authored subjectLabel above, this is not a client-anonymity concern. Only
            // ever resolvable for an individual Therapy -- GroupTherapy org billing was never
            // built (TT-7.3b-b/-c's own scope boundary, carried forward here), so this is
            // deliberately null for a GroupTherapy row rather than an arbitrary "first counsellor".
            'counsellorName' => $subject instanceof Therapy ? $subject->counsellor?->name : null,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'status' => $this->status,
            'counsellorShare' => $earning instanceof CounsellorEarning ? $earning->net_amount : null,
            'platformFee' => $earning instanceof CounsellorEarning ? $earning->fee_amount : null,
            'payoutStatus' => $earning instanceof CounsellorEarning ? $earning->payout?->status : null,
            'createdAt' => $this->created_at,
        ];
    }
}
