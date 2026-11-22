<?php

namespace App\Http\Resources;

use App\Models\PlatformFeeWaiver;
use Illuminate\Http\Request;

// TT-7.11e/SCRUM-475: one counsellor's own commission-free period, the same shape from every route (list, create, end,
// delete) so the SCRUM-474 page can merge responses. Adds the linked recognition and `needsReview`: the period is
// running or coming up but the counsellor is no longer eligible (not verified, suspended, deleted, or the subject of an
// open report) or its recognition is no longer approved. Periods are never ended automatically -- the administrator
// decides. Expects `createdBy`, `endedBy` and `recognition` loaded; `$counsellorEligible` from
// PlatformFeeWaiverService::isEligible().
/** @mixin PlatformFeeWaiver */
class AdminCounsellorFeeWaiverResource extends AdminPlatformFeeWaiverResource
{
    public function __construct(PlatformFeeWaiver $waiver, private bool $counsellorEligible)
    {
        parent::__construct($waiver);
    }

    public function toArray(Request $request): array
    {
        $recognition = $this->recognition;
        $applies = in_array($this->status(), [PlatformFeeWaiver::ACTIVE, PlatformFeeWaiver::UPCOMING], true);

        return [
            ...parent::toArray($request),
            'counsellorId' => $this->counsellor_id,
            'recognitionId' => $recognition?->id,
            'recognitionDecision' => $recognition?->decision?->value,
            'needsReview' => $applies && (! $this->counsellorEligible || ($recognition && ! $recognition->isApproved())),
        ];
    }
}
