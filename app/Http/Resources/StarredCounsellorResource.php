<?php

namespace App\Http\Resources;

use App\Models\User;
use App\Support\CounsellorVerification;
use App\Support\PreferenceRanking;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StarredCounsellorResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $counsellor = $this->resource::class == User::class ? $this->counsellor : $this->resource;

        return [
            'id' => $counsellor->id,
            'name' => $counsellor->getName(),
            'avatar' => $counsellor->avatar?->url,
            'cover' => $counsellor->cover?->url,
            'stars' => $this->stars_count,
            'overallStars' => $this->stars->count(),
            // SCRUM-30: the public state only.
            'verification' => CounsellorVerification::projection($counsellor),
            // SCRUM-113: whether it matched the viewer's preferences (never how).
            'matchesPreferences' => (int) $counsellor->getAttribute(PreferenceRanking::COLUMN) > 0,
        ];
    }
}
