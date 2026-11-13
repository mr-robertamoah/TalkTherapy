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
// Security-engineer finding: `subjectLabel` is deliberately built from system/professional data
// only (SCRUM-385: the counsellor's name, or a group's start date -- no longer a raw "#<id>"),
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
        // SCRUM-420: a group charge has one earning per counsellor running it (a settlement Transaction, which fans
        // out per invoice line, is excluded from this list by GetOrganizationFinancedTransactionsAction), so the row
        // shows their totals and every counsellor's name; a therapy has one.
        $earnings = $this->whenLoaded('earnings', fn () => $this->earnings, collect());
        $payoutStatuses = $earnings->map(fn (CounsellorEarning $earning) => $earning->payout?->status)->unique();

        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'subjectType' => $subject instanceof GroupTherapy ? 'GroupTherapy' : ($subject instanceof Therapy ? 'Therapy' : null),
            'subjectLabel' => $this->subjectLabel($subject),
            // The counsellor's own name is professional/public information (already shown
            // throughout this org's existing dashboard, e.g. CounsellorsSection.vue) -- unlike the
            // client-authored subjectLabel above, this is not a client-anonymity concern. For a group,
            // the counsellors its earnings went to (none for a payment from before SCRUM-420).
            'counsellorName' => $subject instanceof Therapy
                ? $subject->counsellor?->name
                : ($earnings->isNotEmpty() ? $earnings->map(fn (CounsellorEarning $earning) => $earning->counsellor?->name)->filter()->join(', ') : null),
            'amount' => $this->amount,
            'currency' => $this->currency,
            'status' => $this->status,
            'counsellorShare' => $earnings->isNotEmpty() ? $earnings->sum('net_amount') : null,
            'platformFee' => $earnings->isNotEmpty() ? $earnings->sum('fee_amount') : null,
            // One status when they all agree; "MIXED" when some counsellors have been paid out and others not.
            'payoutStatus' => $earnings->isEmpty() ? null : ($payoutStatuses->count() === 1 ? $payoutStatuses->first() : 'MIXED'),
            'createdAt' => $this->created_at,
        ];
    }

    private function subjectLabel(Therapy|GroupTherapy|null $subject): ?string
    {
        if ($subject instanceof GroupTherapy) {
            return 'Group therapy (started '.$subject->created_at->format('j M Y').')';
        }

        if ($subject instanceof Therapy) {
            return $subject->counsellor ? "Therapy with {$subject->counsellor->name}" : 'Therapy';
        }

        return null;
    }
}
