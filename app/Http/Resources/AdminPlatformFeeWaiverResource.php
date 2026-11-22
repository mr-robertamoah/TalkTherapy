<?php

namespace App\Http\Resources;

use App\Models\PlatformFeeWaiver;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

// TT-7.10b/SCRUM-468: a commission-free period as administrators see it -- platform-wide on Admin → Payouts, and the
// base of AdminCounsellorFeeWaiverResource for one counsellor's (TT-7.11e/SCRUM-475). The reason and who created or
// ended it are for administrators only; counsellors and organisations get just the dates (the notice). canEnd and
// canDelete are for super administrators only, and follow the status; the service re-checks. Expects `createdBy` and
// `endedBy` loaded.
/** @mixin PlatformFeeWaiver */
class AdminPlatformFeeWaiverResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $status = $this->status();
        // A period just deleted (returned by the delete route) offers nothing.
        $canManage = $this->exists && (bool) $request->user()?->isSuperAdmin();

        return [
            'id' => $this->id,
            'startsAt' => $this->starts_at->toIso8601ZuluString(),
            'endsAt' => $this->ends_at?->toIso8601ZuluString(),
            'reason' => $this->reason,
            'status' => $status,
            'createdBy' => $this->createdBy?->name,
            'endedBy' => $this->endedBy?->name,
            'deleted' => ! $this->exists,
            'canEnd' => $canManage && $status === PlatformFeeWaiver::ACTIVE,
            // An upcoming period can't have been used by a payment yet (the service still checks).
            'canDelete' => $canManage && $status === PlatformFeeWaiver::UPCOMING,
        ];
    }
}
