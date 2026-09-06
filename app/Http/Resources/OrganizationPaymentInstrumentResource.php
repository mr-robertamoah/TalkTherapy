<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrganizationPaymentInstrumentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            // Already masked at persistence time (CaptureOrganizationPaymentInstrumentAction) --
            // the raw card number is never stored, so there is nothing further to redact here.
            'maskedCardNumber' => $this->masked_card_number,
            'cardType' => $this->card_type,
            'bank' => $this->bank,
            'expMonth' => $this->exp_month,
            'expYear' => $this->exp_year,
            'currency' => $this->currency,
            // Minor units, same convention as transactions.amount. Write-only until something
            // actually credits it against a real invoice (SettingsEnum's own comment on this
            // field says TT-7.3b-e's invoicing was meant to; a grep confirms that never happened
            // -- a pre-existing gap, out of this ticket's scope to fix). Surfaced here anyway
            // since it's meaningful, honest context for the admin either way ("we owe you this").
            'pendingCreditAmount' => $this->pending_credit_amount,
        ];
    }
}
