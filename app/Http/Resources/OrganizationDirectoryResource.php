<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrganizationDirectoryResource extends JsonResource
{
    // Deliberately a curated field set, not OrganizationResource's full admin-facing shape --
    // this is shown to any authenticated user browsing to decide whether to apply, not just an
    // org's own admins. Excludes legalName/registrationNumber/email/phone (internal/admin
    // contact details a prospective applicant has no need to see) and anything about the org's
    // current members/counsellors (that stays admin-only, TT-6.6a).
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'logoUrl' => $this->logo?->url,
            'isProvider' => $this->is_provider,
            'isConsumer' => $this->is_consumer,
            'selfApplyEnabled' => $this->self_apply_enabled,
        ];
    }
}
