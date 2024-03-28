<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TherapyMiniResource extends JsonResource
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
            'name' => $this->name,
            'userId' => $this->addedby?->id,
            'public' => $this->public,
            'anonymous' => $this->anonymous,
            'counsellor' => $this->when($this->counsellor, new CounsellorMiniResource($this->counsellor)),
            'backgroundStory' => $this->background_story,
            'sessionsHeld' => $this->sessionsHeld,
            'createdAt' => $this->created_at->diffForHumans()
        ];
    }
}
