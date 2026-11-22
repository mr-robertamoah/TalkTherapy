<?php

namespace App\Http\Resources;

use App\Actions\Recognition\GetVisibleRecognitionsAction;
use App\Models\TherapyCase;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

// TT-7.11f/SCRUM-476: a recognition as the public showcase shows it -- a CounsellorRecognition from
// GetVisibleRecognitionsAction (which eager-loads all of this). Exactly these keys: no stars or rank, no consent, no
// waiver, nothing about anyone else. `id` is the counsellor's, for the profile link.
class RecognisedCounsellorResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $counsellor = $this->counsellor;

        return [
            'id' => $counsellor->id,
            'name' => $counsellor->getName(),
            'avatar' => $counsellor->avatar?->url,
            'profession' => $counsellor->profession?->name,
            'cases' => $counsellor->cases->take(GetVisibleRecognitionsAction::CASES_SHOWN)->map(fn (TherapyCase $case) => $case->name)->values()->all(),
            'month' => $this->resource->monthLabel(),
        ];
    }
}
