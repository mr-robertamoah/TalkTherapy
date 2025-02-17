<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserMiniResource extends JsonResource
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
            'fullName' => $this->name,
            'username' => $this->username,
            'gender' => $this->gender,
            'country' => $this->country,
            'dob' => $this->dob,
            'isUser' => true,
        ];
    }
}
