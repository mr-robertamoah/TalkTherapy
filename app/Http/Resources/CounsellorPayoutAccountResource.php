<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CounsellorPayoutAccountResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'type' => $this->type,
            'bankName' => $this->bank_name,
            'accountName' => $this->account_name,
            // Already masked at persistence time (CreateCounsellorPayoutDestinationAction) --
            // the raw account number is never stored, so there is nothing further to redact here.
            'maskedAccountNumber' => $this->masked_account_number,
            'currency' => $this->currency,
        ];
    }
}
