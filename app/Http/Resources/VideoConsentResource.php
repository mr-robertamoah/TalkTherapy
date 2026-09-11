<?php

namespace App\Http\Resources;

use App\Models\Session;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

// TT-3.1e-f/SCRUM-285: shapes a VideoConsent row for both the therapy page's current-grant
// display and the co-guardian audit-trail list -- deliberately minimal (guardian name only, not a
// full UserMiniResource) since this is guardian-facing, not the ward's own profile data.
class VideoConsentResource extends JsonResource
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
            'scope' => $this->consentable_type === Session::class ? 'session' : 'therapy',
            'grantedByName' => $this->guardian?->name,
            'grantedAt' => $this->granted_at,
            'isValid' => $this->isValid(),
            'revokedAt' => $this->revoked_at,
            'revokedByName' => $this->revokedByGuardian?->name,
            'revocationReason' => $this->revocation_reason,
        ];
    }
}
