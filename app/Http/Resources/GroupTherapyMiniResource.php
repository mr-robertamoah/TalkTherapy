<?php

namespace App\Http\Resources;

use App\Enums\ConstantsEnum;
use App\Models\Counsellor;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GroupTherapyMiniResource extends JsonResource
{
    private bool $narrowUsers = false;

    // SCRUM-388: the people in it as RequestResource::narrowUserProjection() (no dob, gender or
    // country) -- for admin queue rows every administrator sees.
    public function withNarrowUsers(): static
    {
        $this->narrowUsers = true;

        return $this;
    }

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
                ? ($this->narrowUsers ? (new CounsellorMiniResource($this->addedby))->withNarrowUser() : new CounsellorMiniResource($this->addedby))
                : $this->when(
                    ! $isAnonymous,
                    fn () => $this->narrowUsers ? RequestResource::narrowUserProjection($this->addedby) : new UserMiniResource($this->addedby),
                    // SCRUM-345: never the real id (see GroupTherapyResource).
                    ['id' => null, 'fullName' => ConstantsEnum::anonymousUserLabel->value]
                ),
            'counsellorsCount' => $this->counsellorsCount,
            'about' => $this->about,
            'sessionsHeld' => $this->sessionsHeld,
            'createdAt' => $this->created_at->diffForHumans(),
            // SCRUM-288: the viewer's own state only (memoised on the request's user).
            'viewerAccessPaused' => $this->pauseStateFor($user, counterparty: false)['viewer'],
        ];
    }
}
