<?php

namespace App\Http\Resources;

use App\Enums\CounsellorPayoutStatusEnum;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminCounsellorPayoutResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // The most recent FAILED status-history row's message, if any -- the one place a failure
        // reason (e.g. "Paystack could not initiate this transfer.") is recorded
        // (RecordCounsellorPayoutStatusAction) -- never surfaced on the counsellor-facing
        // CounsellorPayoutResource (TT-7.6d), only here for admin audit purposes.
        $failureMessage = $this->statusHistories
            ->sortByDesc('id')
            ->firstWhere('status', CounsellorPayoutStatusEnum::failed->value)
            ?->message;

        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'counsellorId' => $this->counsellor->id,
            'counsellorName' => $this->counsellor->getName(),
            'initiatedBy' => $this->initiated_by_id === $this->counsellor->user_id
                ? 'Self'
                : $this->initiatedBy?->name,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'status' => $this->status,
            'failureMessage' => $failureMessage,
            'createdAt' => $this->created_at,
        ];
    }
}
