<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

// TT-7.3b-j/SCRUM-241: one row per retainer settlement period -- the org-admin reconciliation
// view's own "invoice/settlement/suspension status" requirement (organization-level suspension
// itself is already exposed on OrganizationResource, not duplicated here).
class OrganizationInvoiceResource extends JsonResource
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
            'currency' => $this->currency,
            'periodStart' => $this->period_start,
            'periodEnd' => $this->period_end,
            'status' => $this->status,
            // Reviewer finding: `amount` alone doesn't satisfy the ticket's own "current-period
            // accrued balance" requirement -- it's null until settlement (the organization_invoices
            // migration's own convention), so a still-`open` invoice would otherwise render no
            // total at all. `accruedAmount` is ALWAYS computed fresh from the loaded lines (works
            // for an open period, and doubles as a sanity cross-check against `amount` once
            // settled), rather than only ever reflecting whatever was true at claim time.
            'amount' => $this->amount,
            'accruedAmount' => $this->whenLoaded(
                'lines',
                fn () => (int) $this->lines->sum(fn ($line) => $line->net_amount + $line->fee_amount)
            ),
            'lines' => OrganizationInvoiceLineResource::collection($this->whenLoaded('lines')),
        ];
    }
}
