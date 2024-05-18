<?php

namespace App\Http\Resources;

use App\Models\Counsellor;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PostResource extends JsonResource
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
            'fromAdmin' => $this->addedby_type == Counsellor::class ? false : $this->addedby?->isAdmin(),
            'counsellor' => $this->addedby_type == Counsellor::class ? new CounsellorMiniResource($this->addedby) : null,
            'content' => $this->content,
            'files' => FileResource::collection($this->files),
            'createdAt' => $this->created_at,
        ];
    }
}
