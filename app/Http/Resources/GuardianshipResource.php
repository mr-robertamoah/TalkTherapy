<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GuardianshipResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $isWard = $request->user()->id == $this->guardian_id;

        // SCRUM-439: the people come eager-loaded (UserService::getGuardianship) -- no query per row. A deleted
        // account is still the "deleted" placeholder, as before (the relation leaves it out).
        return [
            'id' => $this->id,
            'ward' => $this->when($isWard, fn () => new UserMiniResource($this->ward)),
            'guardian' => $this->when(! $isWard, fn () => new UserMiniResource($this->guardian)),
            'createdAt' => $this->created_at?->diffForHumans(),
            // SCRUM-439: a real date, for "Guardian since 3 March 2026".
            'since' => $this->created_at?->toDateString(),
            'isWard' => $isWard,
        ];
    }
}
