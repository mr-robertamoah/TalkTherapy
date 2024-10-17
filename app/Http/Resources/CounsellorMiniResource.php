<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CounsellorMiniResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = [
            'id' => $this->id,
            'userId' => $this->user_id,
            'isCounsellor' => true,
            'deleted' => true,
        ];

        if ($this->deleted_at)
            return $data;

        return array_merge($data, [
            'username' => $this->user->username,
            'name' => $this->getName(),
            'verifiedAt' => $this->verified_at,
            'avatar' => $this->avatar?->url,
        ]);
    }
}
