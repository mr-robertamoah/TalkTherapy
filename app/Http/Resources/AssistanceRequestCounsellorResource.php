<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AssistanceRequestCounsellorResource extends JsonResource
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
            'cases' => ReligionResource::collection($this->cases),
            'languages' => LanguageResource::collection($this->languages),
            'religions' => ReligionResource::collection($this->religions),
            'profession' => new ProfessionResource($this->profession),
            'allTherapiesCount' => $this->allTherapiesCount,
            'avatar' => $this->avatar?->url,
        ];
    }
}
