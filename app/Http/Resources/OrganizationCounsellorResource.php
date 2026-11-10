<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrganizationCounsellorResource extends JsonResource
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
            'status' => $this->status,
            'source' => $this->source,
            'counsellor' => new CounsellorMiniResource($this->whenLoaded('counsellor')),
            'compensation' => $this->when(
                $this->relationLoaded('latestCompensation'),
                fn () => $this->latestCompensation ? new OrganizationCounsellorCompensationResource($this->latestCompensation) : null
            ),
        ];
    }
}
