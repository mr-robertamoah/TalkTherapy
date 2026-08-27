<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrganizationMemberBillingConfigResource extends JsonResource
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
            'organizationMemberId' => $this->organization_member_id,
            'mode' => $this->mode,
            'per' => $this->per,
            'includeGroupTherapies' => $this->include_group_therapies,
            'effectiveFrom' => $this->effective_from,
        ];
    }
}
