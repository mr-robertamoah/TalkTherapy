<?php

namespace App\Http\Resources;

use App\Support\NarrowUserProjection;
use App\Support\PreferenceRanking;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TherapyMiniResource extends JsonResource
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
            'userId' => $isAnonymous ? null : $this->addedby?->id,
            'public' => $this->public,
            'anonymous' => $this->anonymous,
            'counsellor' => $this->when($this->counsellor, fn () => $this->narrowUsers
                ? (new CounsellorMiniResource($this->counsellor))->withNarrowUser()
                : new CounsellorMiniResource($this->counsellor)),
            'backgroundStory' => $this->background_story,
            'sessionsHeld' => $this->sessionsHeld,
            'createdAt' => $this->created_at->diffForHumans(),
            // SCRUM-288: the viewer's own state (memoised on the request's user).
            'viewerAccessPaused' => $this->pauseStateFor($user, counterparty: false)['viewer'],
            // SCRUM-113/114: on the Home and Public lists -- the status, the viewer's button
            // (ResolveTherapyCardStateAction) and whether it matched their preferences (never how).
            'status' => $this->status,
            'statusLabel' => $this->getStatusLabel(),
            ...($this->resource->viewerCard ? [
                'viewer' => [...$this->resource->viewerCard, 'matchesPreferences' => (int) $this->resource->getAttribute(PreferenceRanking::COLUMN) > 0],
            ] : []),
        ];
    }
}
