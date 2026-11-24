<?php

namespace App\Http\Resources;

use App\Models\PlatformFeeWaiver;
use Illuminate\Http\Request;

// TT-7.11e2/SCRUM-477: a period linked to a recognition, in the admin recognition page's "Fee waiver" column -- the
// SCRUM-475 shape (AdminCounsellorFeeWaiverResource), cut down for who is looking:
// - a super administrator gets all of it (the reason, who created or ended it, and canEnd/canDelete);
// - an ordinary administrator gets the dates, the status and `needsReview` -- no reason, nobody's name, no actions;
// - the viewer's own counsellor row (an administrator who is also a counsellor) gets only that a period exists, as the
//   page redacts the rest of that row (CounsellorRecognitionService, security review L1): `needsReview` would tell
//   them they are under an open report.
/** @mixin PlatformFeeWaiver */
class AdminRecognitionFeeWaiverResource extends AdminCounsellorFeeWaiverResource
{
    public function __construct(PlatformFeeWaiver $waiver, bool $counsellorEligible, private ?int $ownCounsellorId)
    {
        parent::__construct($waiver, $counsellorEligible);
    }

    public function toArray(Request $request): array
    {
        if ($this->ownCounsellorId !== null && (int) $this->counsellor_id === $this->ownCounsellorId) {
            return [
                'counsellorId' => (int) $this->counsellor_id,
                'recognitionId' => $this->recognition?->id,
                'redacted' => true,
            ];
        }

        $data = [...parent::toArray($request), 'redacted' => false];

        if ($request->user()?->isSuperAdmin()) {
            return $data;
        }

        return array_diff_key($data, array_flip(['reason', 'createdBy', 'endedBy', 'canEnd', 'canDelete']));
    }
}
