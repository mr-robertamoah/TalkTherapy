<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
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
            'firstName' => $this->firstName,
            'lastName' => $this->lastName,
            'otherNames' => $this->otherNames,
            'fullName' => $this->name,
            'username' => $this->username,
            'email' => $this->email,
            'email_verified_at' => $this->email_verified_at,
            'gender' => $this->gender,
            'country' => $this->country,
            'dob' => $this->dob,
            'isAdult' => $this->dob?->age >= 18,
            'settings' => $this->settings,
            'counsellor' => $this->counsellor ? new CounsellorMiniResource($this->counsellor) : null,
            'createdAt' => $this->created_at->diffForHumans(),
            'isAdmin' => $this->when($this->isAdmin(), true)
        ];
    }
}
