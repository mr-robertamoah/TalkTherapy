<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

// TT-7.3b-followup/SCRUM-245: admin-only surface -- unlike OrganizationResource, this can safely
// expose billingSuspensionReason to any platform admin (not just this org's own admins), since the
// whole point of this list is letting staff act on it.
class AdminBillingSuspendedOrganizationResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'billingSuspendedAt' => $this->billing_suspended_at,
            'billingSuspensionReason' => $this->billing_suspension_reason,
            'hasPaymentInstrument' => (bool) $this->paymentInstrument,
            'latestFailedInvoice' => $this->latestFailedInvoice ? [
                'id' => $this->latestFailedInvoice->id,
                'periodStart' => $this->latestFailedInvoice->period_start,
                'periodEnd' => $this->latestFailedInvoice->period_end,
                'amount' => $this->latestFailedInvoice->amount,
                'currency' => $this->latestFailedInvoice->currency,
            ] : null,
        ];
    }
}
