<?php

namespace App\Http\Resources;

use App\Models\PlatformFeeWaiver;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

// TT-7.10b/SCRUM-468: a platform-wide commission-free period as administrators see it (the reason and who created or
// ended it are for administrators only -- counsellors and organisations get just the dates). Which buttons a row offers
// follows its status; the service re-checks. Expects `createdBy` and `endedBy` loaded.
/** @mixin PlatformFeeWaiver */
class AdminPlatformFeeWaiverResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $status = $this->status();
        $canManage = (bool) $request->user()?->isSuperAdmin();

        return [
            'id' => $this->id,
            'startsAt' => $this->starts_at->toIso8601ZuluString(),
            'endsAt' => $this->ends_at?->toIso8601ZuluString(),
            'reason' => $this->reason,
            'status' => $status,
            'createdBy' => $this->createdBy?->name,
            'endedBy' => $this->endedBy?->name,
            'canEnd' => $canManage && $status === PlatformFeeWaiver::ACTIVE,
            // An upcoming period can't have been used by a payment yet (the service still checks).
            'canDelete' => $canManage && $status === PlatformFeeWaiver::UPCOMING,
        ];
    }
}
