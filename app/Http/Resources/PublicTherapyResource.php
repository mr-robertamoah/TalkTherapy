<?php

namespace App\Http\Resources;

use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Models\User;
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
        $isGroupTherapy = $this->resource instanceof GroupTherapy;
        $user = $request->user();

        // Anonymity only ever applies to a User (client) addedby, never a Counsellor one, and
        // never masks the owner's own view of their own record.
        $addedbyUser = $this->addedby_type == User::class ? $this->addedby : null;
        $isAnonymous = $addedbyUser
            && $this->isAnonymousFor($addedbyUser)
            && ! $addedbyUser->is($user);

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
                'userId' => $isAnonymous
                    ? null
                    : ($this->addedby_type == Counsellor::class
                        ? $this->addedby?->user_id
                        : $this->addedby?->id),
                'counsellorsCount' => $this->counsellors()->count(),
                'about' => $this->about,
            ]);
        }

        return array_merge($baseData, [
            'userId' => $isAnonymous ? null : $this->addedby?->id,
            'counsellor' => $this->when($this->counsellor, new CounsellorMiniResource($this->counsellor)),
            'backgroundStory' => $this->background_story,
        ]);
    }
}
