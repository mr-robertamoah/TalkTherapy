<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

// TT-7.11d1/SCRUM-473: a recognition as an administrator or its counsellor sees it after a change -- no
// person's details, only ids.
class CounsellorRecognitionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'counsellorId' => $this->counsellor_id,
            'month' => $this->resource->monthStart()->format('Y-m'),
            'decision' => $this->decision?->value,
            'consent' => $this->consent?->value,
            'consentChangedAt' => $this->consent_changed_at?->toIso8601ZuluString(),
            'addedManually' => (bool) $this->added_manually,
            // The snapshot taken when it was decided -- for administrators only (the counsellor's own response
            // leaves them out: no star counts or ranks are shown to counsellors). SCRUM-474: any administrator, as
            // the admin page shows every administrator the month's stars and ranks.
            'starsCount' => $this->when($request->user()?->isAdmin(), $this->stars_count),
            'rank' => $this->when($request->user()?->isAdmin(), $this->rank),
            'decidedAt' => $this->decided_at?->toIso8601ZuluString(),
        ];
    }
}
