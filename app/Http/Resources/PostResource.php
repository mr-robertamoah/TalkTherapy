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
        // A public route: the viewer comes from the SPA session when there is one.
        $viewer = $request->user() ?? $request->user('sanctum');

        return [
            'id' => $this->id,
            'fromAdmin' => $this->addedby_type == Counsellor::class ? false : $this->addedby?->isAdmin(),
            'counsellor' => $this->addedby_type == Counsellor::class
                ? new CounsellorMiniResource($this->addedby)
                : ($this->addedby?->counsellor ? new CounsellorMiniResource($this->addedby->counsellor) : null),
            'content' => $this->content,
            // SCRUM-355: how many, and whether you did -- never who. On a mental-health platform a
            // list of who liked what is itself sensitive. A public route, so the viewer comes from
            // the SPA session when there is one.
            'likesCount' => $this->likes->count(),
            'isLiked' => (bool) $viewer && $this->likes->contains('user_id', $viewer->id),
            'comments' => $this->comments()->count(),
            'files' => FileResource::collection($this->files),
            'createdAt' => $this->created_at->diffForHumans(),
        ];
    }
}
