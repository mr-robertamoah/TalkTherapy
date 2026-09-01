<?php

namespace App\Http\Resources;

use App\Enums\ConstantsEnum;
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
        $user = $request->user();
        $isAnonymous = $this->addedByUserIsMaskedFor($user);

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
                    ['id' => $this->addedby?->id, 'fullName' => ConstantsEnum::anonymousUserLabel->value]
                ),
            'counsellorsCount' => $this->counsellorsCount,
            'about' => $this->about,
            'sessionsHeld' => $this->sessionsHeld,
            'createdAt' => $this->created_at->diffForHumans(),
        ];
    }
}
