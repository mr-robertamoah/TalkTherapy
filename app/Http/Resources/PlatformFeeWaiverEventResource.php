<?php

namespace App\Http\Resources;

use App\Models\PlatformFeeWaiverEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

// TT-7.11e/SCRUM-475: one change to a commission-free period, for super administrators only (a deleted period's event
// carries its reason). Expects `user` loaded.
/** @mixin PlatformFeeWaiverEvent */
class PlatformFeeWaiverEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $data = $this->data ?? [];

        return [
            'id' => $this->id,
            'waiverId' => $this->platform_fee_waiver_id,
            'event' => $this->event->value,
            'by' => $this->user?->name,
            'at' => $this->created_at?->toIso8601ZuluString(),
            'startsAt' => $data['starts_at'] ?? null,
            'endsAt' => $data['ends_at'] ?? null,
            'scheduledEndsAt' => $data['scheduled_ends_at'] ?? null,
            'reason' => $data['reason'] ?? null,
            'recognitionId' => $data['counsellor_recognition_id'] ?? null,
        ];
    }
}
