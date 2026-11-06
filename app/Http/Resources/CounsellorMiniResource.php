<?php

namespace App\Http\Resources;

use App\Support\CounsellorVerification;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CounsellorMiniResource extends JsonResource
{
    private bool $narrowUser = false;

    // SCRUM-388: the nested user as RequestResource::narrowUserProjection() (no dob, gender or
    // country) -- for rows every administrator sees in the admin queue.
    public function withNarrowUser(): static
    {
        $this->narrowUser = true;

        return $this;
    }

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        if (is_null($this->resource)) {
            return ['deleted' => true, 'isCounsellor' => true];
        }

        $data = [
            'id' => $this->id,
            'userId' => $this->user_id,
            'isCounsellor' => true,
        ];

        if ($this->deleted_at) {
            $data['deleted'] = true;

            return $data;
        }

        return array_merge($data, [
            'username' => $this->user->username,
            'name' => $this->getName(),
            // SCRUM-30: the public state only (columns, no query) -- every list shows its badge.
            'verification' => CounsellorVerification::projection($this->resource),
            'avatar' => $this->avatar?->url,
            'user' => $this->narrowUser ? RequestResource::narrowUserProjection($this->user) : new UserMiniResource($this->user),
        ]);
    }
}
