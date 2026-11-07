<?php

namespace App\Http\Resources;

use App\Enums\ConstantsEnum;
use App\Models\Counsellor;
use App\Support\NarrowUserProjection;
use App\Support\PreferenceRanking;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GroupTherapyMiniResource extends JsonResource
{
    private bool $narrowUsers = false;

    // SCRUM-388: the people in it as NarrowUserProjection::of() (no dob, gender or
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
                    fn () => $this->narrowUsers ? NarrowUserProjection::of($this->addedby) : new UserMiniResource($this->addedby),
                    // SCRUM-345: never the real id (see GroupTherapyResource).
                    ['id' => null, 'fullName' => ConstantsEnum::anonymousUserLabel->value]
                ),
            'counsellorsCount' => $this->counsellorsCount,
            'about' => $this->about,
            'sessionsHeld' => $this->sessionsHeld,
            'createdAt' => $this->created_at->diffForHumans(),
            // SCRUM-288: the viewer's own state only (memoised on the request's user).
            'viewerAccessPaused' => $this->pauseStateFor($user, counterparty: false)['viewer'],
            // SCRUM-113/114: on the Home and Public lists -- status and places, the viewer's button
            // (ResolveTherapyCardStateAction) and whether it matched their preferences (never how).
            'status' => $this->status,
            'statusLabel' => $this->getStatusLabel(),
            'maxUsers' => $this->max_users,
            ...($this->resource->viewerCard ? [
                'memberCount' => $this->resource->relationLoaded('users') ? $this->users->count() : null,
                'viewer' => [...$this->resource->viewerCard, 'matchesPreferences' => (int) $this->resource->getAttribute(PreferenceRanking::COLUMN) > 0],
            ] : []),
        ];
    }
}
