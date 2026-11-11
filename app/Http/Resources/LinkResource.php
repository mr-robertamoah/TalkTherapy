<?php

namespace App\Http\Resources;

use App\Enums\LinkTypeEnum;
use App\Models\Counsellor;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LinkResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $to = null;
        $for = null;
        $forId = null;

        if ($this->to) {
            $to = $this->to::class == Counsellor::class
                ? new CounsellorMiniResource($this->to)
                : new UserMiniResource($this->to);
        }
        if ($this->for) {
            $for = str_replace('App\Models\\', '', $this->for_type);
            $forId = $this->for_id;
        }

        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'type' => $this->type,
            'state' => $this->state,
            // SCRUM-415: a used link (or any organisation join link) is never switched back on.
            'canReactivate' => ! $this->isUsed() && $this->type !== LinkTypeEnum::organizationSelfApply->value,
            'to' => $to,
            'forType' => $for,
            'forId' => $forId,
            'createdAt' => $this->created_at->diffForHumans(),
        ];
    }
}
