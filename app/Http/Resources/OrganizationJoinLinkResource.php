<?php

namespace App\Http\Resources;

use App\Enums\LinkStateEnum;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

// SCRUM-415: one of an organisation's join links, for its admins. The uuid (what the URL is made of) only
// while the link can still be used -- there's nothing to copy once it can't.
class OrganizationJoinLinkResource extends JsonResource
{
    public const ACTIVE = 'ACTIVE';

    public const USED = 'USED';

    public const REVOKED = 'REVOKED';

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $active = $this->state === LinkStateEnum::active->value;

        return [
            'id' => $this->id,
            'uuid' => $active ? $this->uuid : null,
            // Used before used_at existed reads as revoked: either way it can't be used again.
            'status' => $active ? self::ACTIVE : ($this->isUsed() ? self::USED : self::REVOKED),
            'createdBy' => $this->addedby?->name,
            'createdAt' => $this->created_at->diffForHumans(),
        ];
    }
}
