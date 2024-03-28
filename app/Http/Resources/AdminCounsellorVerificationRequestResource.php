<?php

namespace App\Http\Resources;

use App\Models\License;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminCounsellorVerificationRequestResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'from' => $this->from->getName(),
            'counsellor' => [
                'id' => $this->from->id,
                'name' => $this->from->getName(),
                'username' => $this->from->user->username,
                'profession' => $this->when($this->from->profession, new ProfessionResource($this->from->profession)),
                'phone' => $this->from->phone,
                'email' => $this->from->email,
                'avatar' => $this->from->avatar?->url,
            ],
            'id' => $this->id,
            'type' => $this->type,
            'status' => $this->status,
            'nationalIdLicense' => new LicenseResource(License::find($this->data['nationalIdLicense'])),
            'otherLicense' => new LicenseResource(License::find($this->data['otherLicense'])),
        ];
    }
}
