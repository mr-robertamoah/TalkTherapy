<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrganizationResource extends JsonResource
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
            'name' => $this->name,
            'legalName' => $this->legal_name,
            'registrationNumber' => $this->registration_number,
            'description' => $this->description,
            'email' => $this->email,
            'phone' => $this->phone,
            'logo' => $this->logo?->url,
            'isProvider' => $this->is_provider,
            'isConsumer' => $this->is_consumer,
            'selfApplyEnabled' => $this->self_apply_enabled,
            'isVerified' => $this->isVerified(),
            // TT-7.3b-f2/SCRUM-238: exposed here (not a separate resource) since it's a
            // first-class fact about the org, same as isVerified above -- read by the org-admin
            // dashboard AND the reconciliation view (TT-7.3b-j). billingSuspensionReason is an
            // internal ops detail (e.g. "settlement failed for period X"), never shown to a
            // member. Reviewer finding (correcting an earlier, inaccurate version of this
            // comment): this resource is actually constructed in 6 places, not 2 --
            // OrganizationController's show()/dashboard()/update() (all admin-gated via
            // EnsureUserIsOrganizationAdminAction) and store() (genuinely ungated, but safe: it
            // only ever describes the org the caller just created, which starts unsuspended with
            // no reason set, and can never describe a DIFFERENT, pre-existing org) -- plus
            // OrganizationReconciliationController's index() (TT-7.3b-j) and
            // OrganizationPaymentInstrumentController's index() (TT-7.3b-i), both admin-gated. No
            // path exposes another org's suspension reason to a non-admin.
            'isBillingSuspended' => $this->isBillingSuspended(),
            'billingSuspendedAt' => $this->billing_suspended_at,
            'billingSuspensionReason' => $this->billing_suspension_reason,
            'createdAt' => $this->created_at,
        ];
    }
}
