<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrganizationResource extends JsonResource
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
            'name' => $this->name,
            'legalName' => $this->legal_name,
            'registrationNumber' => $this->registration_number,
            'description' => $this->description,
            'email' => $this->email,
            'phone' => $this->phone,
            'isProvider' => $this->is_provider,
            'isConsumer' => $this->is_consumer,
            'selfApplyEnabled' => $this->self_apply_enabled,
            'isVerified' => $this->isVerified(),
            'createdAt' => $this->created_at,
        ];
    }
}
