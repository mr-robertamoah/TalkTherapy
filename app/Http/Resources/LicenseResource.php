<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LicenseResource extends JsonResource
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
            'number' => $this->number,
            'validated' => $this->validated,
            'licensingAuthority' => new LicensingAuthorityResource($this->licensingAuthority),
            'file' => FileResource::collection($this->files)
        ];
    }
}
