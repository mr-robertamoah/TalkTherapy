<?php

namespace App\Http\Resources;

use App\Models\Counsellor;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DiscussionResource extends JsonResource
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
            'status' => $this->status,
            'addedby' => $this->addedby::class == Counsellor::class
                ? new CounsellorMiniResource($this->addedby)
                : new UserMiniResource($this->addedby),
            'description' => $this->description,
            'session' => $this->session ? new SessionMiniResource($this->session) : null,
            'startTime' => $this->start_time,
            'endTime' => $this->end_time,
            'forType' => str_replace('App\Models\\', '', $this->for_type),
            'forId' => $this->for_id,
            'counsellors' => [],
            'createdAt' => $this->created_at->diffForHumans(),
        ];
    }
}
