<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrganizationCounsellorCompensationResource extends JsonResource
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
            'organizationCounsellorId' => $this->organization_counsellor_id,
            'type' => $this->type,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'percentage' => $this->percentage,
            'basis' => $this->basis,
            'effectiveFrom' => $this->effective_from,
            // Accountability trail (SCRUM-123) -- who set these terms, not the admin's full
            // profile, which the counsellor viewing this has no other reason to see.
            'setBy' => $this->setBy ? ['id' => $this->setBy->id, 'fullName' => $this->setBy->name] : null,
        ];
    }
}
