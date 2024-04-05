<?php

namespace App\Http\Resources;

use App\Enums\RequestTypeEnum;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RequestResource extends JsonResource
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
            'from' => $this->from::class == User::class ? new UserMiniResource($this->from) : new CounsellorMiniResource($this->from),
            'for' => $this->getFor(),
            'to' => $this->to::class == User::class ? new UserMiniResource($this->to) : new CounsellorMiniResource($this->to),
            'status' => $this->status,
            'type' => $this->type,
            'createdAt' => $this->created_at->diffForHumans(),
        ];
    }

    private function getFor()
    {
        if ($this->type == RequestTypeEnum::therapy->value)
            return new TherapyMiniResource($this->for);

        return new CounsellorMiniResource($this->for);
    }
}
