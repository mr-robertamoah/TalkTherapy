<?php

namespace App\Http\Resources;

use App\Models\License;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminCounsellorResource extends JsonResource
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
            'name' => $this->getName(),
            'username' => $this->user->username,
            'profession' => $this->when($this->profession, new ProfessionResource($this->profession)),
            'phone' => $this->phone,
            'email' => $this->email,
            'avatar' => $this->avatar?->url,
            // TODO add some stats
        ];
    }
}
