<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

// TT-7.3b-j/SCRUM-241: one row per retainer-covered session settled (or accruing) within an
// invoice period -- session-metadata-only, never SessionNote/journal content, mirroring the same
// data-minimization boundary as OrganizationFinancedTransactionResource.
//
// Security-engineer finding: `sessionLabel` is an opaque "Session #<id>" reference built directly
// off session_id, deliberately never `Session::name` -- like Therapy's own name, that's
// client-authored free text with no constraint against containing identity/condition-revealing
// content, and this org-billing admin has no other legitimate route to session content at all.
// Built off the raw id (not the loaded relation), so no eager-load is even needed for this field,
// and it stays correct even if the session is later soft-deleted.
class OrganizationInvoiceLineResource extends JsonResource
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
            'sessionId' => $this->session_id,
            'sessionLabel' => "Session #{$this->session_id}",
            // The counsellor's own name is professional/public information, not a client-anonymity
            // concern (see OrganizationFinancedTransactionResource's identical reasoning).
            'counsellorName' => $this->whenLoaded('counsellor', fn () => $this->counsellor?->name),
            'netAmount' => $this->net_amount,
            'feeAmount' => $this->fee_amount,
            'currency' => $this->currency,
        ];
    }
}
