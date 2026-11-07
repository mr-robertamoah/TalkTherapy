<?php

namespace App\Http\Resources;

use App\Models\GroupTherapy;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

// The Public Therapies page's rows (a therapy or a group). SCRUM-113: the same as the mini resources
// the Home rows use -- with each card's button and the preference label -- plus which kind it is.
// It used to be a third, drifting copy of them.
class PublicTherapyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $isGroup = $this->resource instanceof GroupTherapy;
        $resource = $isGroup ? new GroupTherapyMiniResource($this->resource) : new TherapyMiniResource($this->resource);

        return [...$resource->toArray($request), 'type' => $isGroup ? 'group' : 'individual'];
    }
}
