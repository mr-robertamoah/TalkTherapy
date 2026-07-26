<?php

namespace App\Http\Resources;

use App\Models\Counsellor;
use App\Models\User;
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
        $user = $request->user();

        // Anonymity only ever applies to a User (client) addedby, never a Counsellor one, and
        // never masks the owner's own view of their own record.
        $addedbyUser = $this->addedby_type == User::class ? $this->addedby : null;
        $isAnonymous = $addedbyUser
            && $this->isAnonymousFor($addedbyUser)
            && ! $addedbyUser->is($user);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'public' => $this->public,
            'anonymous' => $this->anonymous,
            'allowAnyone' => $this->allow_anyone,
            'userId' => $isAnonymous
                ? null
                : ($this->addedby_type == Counsellor::class
                    ? $this->addedby?->user_id
                    : $this->addedby?->id),
            'addedby' => $this->addedby_type == Counsellor::class
                ? new CounsellorMiniResource($this->addedby)
                : $this->when(
                    ! $isAnonymous,
                    new UserMiniResource($this->addedby),
                    ['id' => $this->addedby?->id, 'fullName' => 'anonymous']
                ),
            'counsellorsCount' => $this->counsellors()->count(),
            'about' => $this->about,
            'sessionsHeld' => $this->sessionsHeld,
            'createdAt' => $this->created_at->diffForHumans(),
        ];
    }
}
