<?php

namespace App\Http\Resources;

use App\Support\CounsellorVerification;
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
            // SCRUM-31: the admin view of their verification.
            'verification' => CounsellorVerification::projection($this->resource, $request->user()),
            // TODO add some stats
        ];
    }
}
