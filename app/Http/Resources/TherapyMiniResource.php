<?php

namespace App\Http\Resources;

use App\Models\User;
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
        $user = $request->user();

        // Anonymity only ever applies to a User (client) addedby, never a Counsellor one (this
        // resource is also reused for GroupTherapy, whose addedby can be either), and never masks
        // the owner's own view of their own record.
        $addedbyUser = $this->addedby_type == User::class ? $this->addedby : null;
        $isAnonymous = $addedbyUser
            && $this->isAnonymousFor($addedbyUser)
            && ! $addedbyUser->is($user);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'userId' => $isAnonymous ? null : $this->addedby?->id,
            'public' => $this->public,
            'anonymous' => $this->anonymous,
            'counsellor' => $this->when($this->counsellor, new CounsellorMiniResource($this->counsellor)),
            'backgroundStory' => $this->background_story,
            'sessionsHeld' => $this->sessionsHeld,
            'createdAt' => $this->created_at->diffForHumans(),
        ];
    }
}
