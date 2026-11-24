<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

// TT-7.11f/SCRUM-476: one of a counsellor's own approved recognitions, for the consent controls on their own profile
// (CounsellorService::getCounsellorData(), the owner only). The month and their consent -- never stars, rank, who
// decided or any waiver. `consentRequestId` is the pending consent request (answered through requests.respond), when
// there is one.
class OwnCounsellorRecognitionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'month' => $this->resource->monthLabel(),
            'consent' => $this->consent?->value,
            'consentRequestId' => $this->consentRequests->first()?->id,
        ];
    }
}
