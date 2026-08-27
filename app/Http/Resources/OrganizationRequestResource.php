<?php

namespace App\Http\Resources;

use App\Models\Counsellor;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

// Covers RequestTypeEnum::organization/organizationCounsellorInvite/organizationCounsellorApplication
// -- these are the only types where `from`/`to` can be an Organization, which the generic
// RequestResource's getFrom()/getTo() don't account for (they assume any non-User `from`/`to`
// is a Counsellor).
class OrganizationRequestResource extends JsonResource
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
            'type' => $this->type,
            'status' => $this->status,
            'from' => $this->partyResource($this->from_type, $this->from),
            'to' => $this->partyResource($this->to_type, $this->to),
            'organization' => new OrganizationMiniResource($this->for),
            'createdAt' => $this->created_at->diffForHumans(),
        ];
    }

    private function partyResource(?string $type, $model)
    {
        if ($type === Organization::class) {
            return new OrganizationMiniResource($model);
        }

        if ($type === Counsellor::class) {
            return new CounsellorMiniResource($model);
        }

        if ($type === User::class) {
            return new UserMiniResource($model);
        }

        return null;
    }
}
