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
            'emailVerifiedAt' => $this->email_verified_at,
            'avatar' => $this->avatar?->url,
            'gender' => $this->gender,
            'isUser' => true,
            'isGuardian' => (bool) $this->wards()->count(),
            'isWard' => (bool) $this->guardians()->count(),
            'country' => $this->country,
            'dob' => $this->dob,
            'isAdult' => $this->age >= 18,
            'settings' => $this->settings,
            'counsellor' => $this->counsellor ? new CounsellorMiniResource($this->counsellor) : null,
            'createdAt' => $this->created_at->diffForHumans(),
            'isAdmin' => $this->when($this->isAdmin(), true, false),
            // SCRUM-360: only about the signed-in user themselves (it drives their own admin menu) --
            // never revealed about anyone else in a list.
            'isSuperAdmin' => $request->user()?->is($this->resource) && $this->isAdmin() && $this->isSuperAdmin(),
        ];
    }
}
