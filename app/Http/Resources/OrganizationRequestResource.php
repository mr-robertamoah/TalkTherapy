<?php

namespace App\Http\Resources;

use App\Enums\RequestTypeEnum;
use App\Http\Resources\Concerns\ResolvesOrganizationOrCounsellorParty;
use App\Models\OrganizationCounsellor;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

// Covers RequestTypeEnum::organization/organizationCounsellorInvite/organizationCounsellorApplication/
// organizationMemberInvite/organizationMemberApplication/organizationCounsellorCompensationChange --
// these are the only types where `from`/`to` can be an Organization, which the generic
// RequestResource's getFrom()/getTo() don't account for (they assume any non-User `from`/`to` is
// a Counsellor).
class OrganizationRequestResource extends JsonResource
{
    use ResolvesOrganizationOrCounsellorParty;

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
            // SCRUM-146 (TT-6.4c): `for` is an OrganizationCounsellor affiliation for the new
            // compensation-change type, not the Organization directly -- resolve through it so
            // this field stays a stable Organization regardless of which type is being rendered.
            'organization' => new OrganizationMiniResource(
                $this->for_type === OrganizationCounsellor::class ? $this->for->organization : $this->for
            ),
            // SCRUM-146 (TT-6.4c): only meaningful for the compensation-change type -- every
            // other type in this resource has no proposal terms/expiry/round to show.
            'proposedTerms' => $this->when($this->type === RequestTypeEnum::organizationCounsellorCompensationChange->value, $this->data),
            'expiresAt' => $this->when(! is_null($this->expires_at), fn () => $this->expires_at?->diffForHumans()),
            'round' => $this->when(! is_null($this->round), $this->round),
            'createdAt' => $this->created_at->diffForHumans(),
        ];
    }
}
