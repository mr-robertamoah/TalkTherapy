<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CounsellorPricingResource extends JsonResource
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
            'counsellorId' => $this->counsellor_id,
            'therapyType' => $this->therapy_type,
            'sessionType' => $this->session_type,
            'per' => $this->per,
            'amount' => $this->amount,
            'currency' => $this->currency,
        ];
    }
}
