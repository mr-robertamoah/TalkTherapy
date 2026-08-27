<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrganizationMiniResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        if (is_null($this->resource)) {
            return ['deleted' => true, 'isOrganization' => true];
        }

        return [
            'id' => $this->id,
            'name' => $this->name,
            'isVerified' => $this->isVerified(),
            'isOrganization' => true,
        ];
    }
}
