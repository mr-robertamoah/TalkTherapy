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
            'emailVerified' => !!$this->email_verified_at,
            'dob' => $this->dob,
            'age' => $this->age,
            'createdAt' => $this->created_at,
        ];
    }
}
