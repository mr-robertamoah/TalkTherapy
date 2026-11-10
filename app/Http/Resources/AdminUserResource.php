<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminUserResource extends JsonResource
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
            'gender' => $this->gender,
            'country' => $this->country,
            'username' => $this->username,
            'otherNames' => $this->otherNames,
            'email' => $this->email,
            'emailVerified' => (bool) $this->email_verified_at,
            'dob' => $this->dob,
            'age' => $this->age,
            'createdAt' => $this->created_at,
            // SCRUM-366: links an administrator to their read log. Only when loaded (no query per row).
            'isAdmin' => $this->relationLoaded('administrator') ? (bool) $this->administrator : null,
            // SCRUM-193: AdminUserComponent shows it beside the username in the admin users list.
            'avatar' => $this->avatar?->url,
        ];
    }
}
