<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CounsellorEarningResource extends JsonResource
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
            // Minor units (pesewas/cents), same as Transaction::amount it derives from -- unlike
            // CounsellorPricing/paymentData amounts (already major units at the source), this
            // genuinely needs /100 on the frontend for display.
            'grossAmount' => $this->gross_amount,
            'feeAmount' => $this->fee_amount,
            'netAmount' => $this->net_amount,
            'currency' => $this->currency,
            'shareBasis' => $this->share_basis,
            'sharePercentage' => $this->share_percentage,
            'status' => $this->status,
            'createdAt' => $this->created_at,
        ];
    }
}
