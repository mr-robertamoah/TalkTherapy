<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

// TT-7.7c/SCRUM-251: used both for the admin review queue's own listing and as
// GetRequestResourceAction's `RequestTypeEnum::refund` branch (the JSON returned from the shared
// accept/reject endpoint, RequestController::respond()) -- neither existing RequestResource nor
// OrganizationRequestResource fits, since a refund's `for` is a Transaction, not a
// Therapy/Organization/OrganizationCounsellor either of them already knows how to resolve.
class RefundRequestResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $transaction = $this->for;

        return [
            'id' => $this->id,
            'status' => $this->status,
            'type' => $this->type,
            'from' => new UserMiniResource($this->from),
            'reason' => $this->data['reason'] ?? null,
            'rejectionReason' => $this->data['rejectionReason'] ?? null,
            'transaction' => $transaction ? [
                'id' => $transaction->id,
                'reference' => $transaction->reference,
                'amount' => $transaction->amount,
                'currency' => $transaction->currency,
                // Best-effort context for the admin reviewing this -- never guaranteed to exist
                // (a refund-eligible transaction's `for` is always a Therapy or Session in
                // practice, but nothing here hard-requires it).
                'subjectName' => $transaction->for?->name ?? null,
            ] : null,
            'createdAt' => $this->created_at->diffForHumans(),
        ];
    }
}
