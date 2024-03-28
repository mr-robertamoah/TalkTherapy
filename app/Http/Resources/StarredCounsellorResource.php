<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StarredCounsellorResource extends JsonResource
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
            'name' => $this->getName(),
            'avatar' => $this->avatar?->url,
            'cover' => $this->cover?->url,
            'stars' => $this->stars_count,
            'overallStars' => $this->stars->count()
        ];
    }
}
