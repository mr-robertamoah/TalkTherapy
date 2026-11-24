<?php

namespace App\Http\Resources;

use App\Support\CounsellorVerification;
use App\Support\PreferenceRanking;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

// A counsellor's card on Home (the "Leading" and "Counsellors" rows, and api.counsellors.random). TT-7.11f/SCRUM-476:
// was StarredCounsellorResource -- no star counts any more, for anyone (the user's decision: no public counts).
class CounsellorCardResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->getName(),
            'avatar' => $this->avatar?->url,
            'cover' => $this->cover?->url,
            // SCRUM-30: the public state only.
            'verification' => CounsellorVerification::projection($this->resource),
            // SCRUM-113: whether it matched the viewer's preferences (never how).
            'matchesPreferences' => (int) $this->getAttribute(PreferenceRanking::COLUMN) > 0,
        ];
    }
}
