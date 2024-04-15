<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TherapyTopicResource extends JsonResource
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
            'userId' => $this->counsellor->user->id,
            'description' => $this->description,
            'sessions' => SessionMiniResource::collection($this->sessions),
            'createdAt' => $this->created_at->diffForHumans(),
        ];
    }
}
