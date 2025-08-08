<?php

namespace App\Http\Resources;

use App\Models\Counsellor;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PublicTherapyResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // Check if this is a GroupTherapy model
        $isGroupTherapy = $this->resource instanceof \App\Models\GroupTherapy;
        
        $baseData = [
            'id' => $this->id,
            'name' => $this->name,
            'public' => $this->public,
            'anonymous' => $this->anonymous,
            'type' => $isGroupTherapy ? 'group' : 'individual',
            'createdAt' => $this->created_at->diffForHumans(),
            'sessionsHeld' => $this->sessionsHeld ?? 0,
        ];

        if ($isGroupTherapy) {
            return array_merge($baseData, [
                'allowAnyone' => $this->allow_anyone,
                'maxUsers' => $this->max_users,
                'userId' => $this->when(
                    $this->addedby_type == Counsellor::class, 
                    $this->addedby->user_id,
                    $this->addedby->id
                ),
                'counsellorsCount' => $this->counsellors()->count(),
                'about' => $this->about,
            ]);
        }

        return array_merge($baseData, [
            'userId' => $this->addedby?->id,
            'counsellor' => $this->when($this->counsellor, new CounsellorMiniResource($this->counsellor)),
            'backgroundStory' => $this->background_story,
        ]);
    }
}