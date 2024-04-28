<?php

namespace App\Http\Resources;

use App\Models\Counsellor;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TestimonialResource extends JsonResource
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
            'by' => $this->addedby_type == Counsellor::class ? 'Counsellor' : 'User',
            'addedby' => $this->addedby_type == Counsellor::class
                ? new CounsellorMiniResource($this->addedby)
                : new UserMiniResource($this->addedby),
            'content' => $this->content,
            'use' => $this->use,
            'createdAt' => $this->created_at,
        ];
    }
}
