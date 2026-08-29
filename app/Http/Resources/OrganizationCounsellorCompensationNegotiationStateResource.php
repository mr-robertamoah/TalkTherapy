<?php

namespace App\Http\Resources;

use App\Enums\RequestStatusEnum;
use App\Http\Resources\Concerns\ResolvesOrganizationOrCounsellorParty;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

// SCRUM-150 (TT-6.4c, 5/5): a small, additive read describing the current negotiation state for
// an affiliation -- entirely separate from OrganizationCounsellorCompensationResource's
// accepted-terms history (SCRUM-123), which this never touches.
class OrganizationCounsellorCompensationNegotiationStateResource extends JsonResource
{
    use ResolvesOrganizationOrCounsellorParty;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        if (is_null($this->resource)) {
            return ['state' => 'none'];
        }

        $data = [
            'state' => $this->status === RequestStatusEnum::pending->value ? 'pending' : 'resolved',
            'status' => $this->status,
            'round' => $this->round,
            'from' => $this->partyResource($this->from_type, $this->from),
            'to' => $this->partyResource($this->to_type, $this->to),
            // Security review (PR #89): whitelisted, not a raw spread of `data` -- that column
            // also carries `proposedById` (an internal User id, used only for
            // set_by_id attribution on accept), which must never reach either negotiating party.
            'proposedTerms' => [
                'type' => $this->data['type'] ?? null,
                'amount' => $this->data['amount'] ?? null,
                'currency' => $this->data['currency'] ?? null,
                'percentage' => $this->data['percentage'] ?? null,
                'basis' => $this->data['basis'] ?? null,
            ],
        ];

        if ($this->status === RequestStatusEnum::pending->value) {
            $data['expiresAt'] = $this->expires_at?->diffForHumans();
        }

        if ($this->status === RequestStatusEnum::rejected->value) {
            // SCRUM-149's signal: absent (a manual reject/counter-supersede) vs 'expiry' (the
            // daily sweep auto-resolved it unanswered) -- AC3's "distinguishably" requirement.
            $data['resolvedBy'] = $this->data['resolvedBy'] ?? 'response';
        }

        return $data;
    }
}
