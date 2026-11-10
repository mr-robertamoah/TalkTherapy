<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrganizationMemberResource extends JsonResource
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
            // Deliberately NOT the full UserMiniResource (gender/country/dob) -- an org admin
            // configuring billing has no legitimate need for a member's personal profile fields,
            // mirroring the same data-minimization call already made for invite() above
            // (SCRUM-124). Security review flagged reusing UserMiniResource here as a regression
            // of that decision (SCRUM-159).
            'user' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'fullName' => $this->user->name,
                'username' => $this->user->username,
            ]),
            'billingConfig' => $this->when(
                $this->relationLoaded('latestBillingConfig'),
                fn () => $this->latestBillingConfig ? new OrganizationMemberBillingConfigResource($this->latestBillingConfig) : null
            ),
        ];
    }
}
