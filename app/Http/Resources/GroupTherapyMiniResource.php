<?php

namespace App\Http\Resources;

use App\Models\Counsellor;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GroupTherapyMiniResource extends JsonResource
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
            'public' => $this->public,
            'anonymous' => $this->anonymous,
            'allowAnyone' => $this->allow_anyone,
            'userId' => $this->when(
                $this->addedby_type == Counsellor::class, 
                $this->addedby->user_id,
                $this->addedby->id
            ),
            'addedby' => $this->when(
                $this->addedby_type == Counsellor::class, 
                new CounsellorMiniResource($this->addedby),
                new UserMiniResource($this->addedby)
            ),
            'counsellorsCount' => $this->counsellors()->count(),
            'about' => $this->about,
            'sessionsHeld' => $this->sessionsHeld,
            'createdAt' => $this->created_at->diffForHumans()
        ];
    }
}
