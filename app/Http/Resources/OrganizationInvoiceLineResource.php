<?php

namespace App\Http\Resources;

use App\Models\GroupTherapy;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

// TT-7.3b-j/SCRUM-241: one row per retainer-covered session settled (or accruing) within an
// invoice period -- session-metadata-only, never SessionNote/journal content, mirroring the same
// data-minimization boundary as OrganizationFinancedTransactionResource.
//
// Security-engineer finding: `sessionLabel` is deliberately never `Session::name` -- like Therapy's
// own name, that's client-authored free text with no constraint against containing
// identity/condition-revealing content, and this org-billing admin has no other legitimate route
// to session content at all. SCRUM-385: it's the session's start date ("Session on 3 Feb 2026")
// rather than a raw "#<id>"; the session relation is eager-loaded withTrashed() by
// GetOrganizationRetainerInvoicesAction so a later soft-deleted session still gets its date.
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
            // SCRUM-420: a group session has a line per covered member and counsellor -- never naming the member.
            'sessionLabel' => ($this->session?->for_type === GroupTherapy::class ? 'Group session' : 'Session')
                .($this->session?->start_time ? ' on '.$this->session->start_time->format('j M Y') : ''),
            // The counsellor's own name is professional/public information, not a client-anonymity
            // concern (see OrganizationFinancedTransactionResource's identical reasoning).
            'counsellorName' => $this->whenLoaded('counsellor', fn () => $this->counsellor?->name),
            'netAmount' => $this->net_amount,
            'feeAmount' => $this->fee_amount,
            'currency' => $this->currency,
        ];
    }
}
