<?php

namespace App\Http\Resources;

use App\Support\NarrowUserProjection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

// SCRUM-387 (review): a refund the provider failed, on the admin refund requests page. Nothing
// retries one (RecordRefundStatusAction), so it is listed for an administrator to follow up by hand.
// The payer is name and username only, as on the requests above it (RequestResource).
class AdminFailedRefundResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // Preloaded: the refund's failed status changes, latest first.
        $failure = $this->statusHistories->first();

        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'from' => NarrowUserProjection::of($this->requestedBy),
            'failureReason' => $failure?->message,
            'failedAt' => ($failure?->created_at ?? $this->updated_at)?->toIso8601String(),
        ];
    }
}
