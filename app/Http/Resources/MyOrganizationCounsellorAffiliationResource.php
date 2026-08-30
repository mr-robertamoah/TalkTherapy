<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MyOrganizationCounsellorAffiliationResource extends JsonResource
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
            'organization' => new OrganizationMiniResource($this->whenLoaded('organization')),
            'compensation' => $this->when(
                $this->relationLoaded('latestCompensation'),
                fn () => $this->latestCompensation ? new OrganizationCounsellorCompensationResource($this->latestCompensation) : null
            ),
        ];
    }
}
