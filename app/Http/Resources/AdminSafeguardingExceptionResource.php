<?php

namespace App\Http\Resources;

use App\Actions\SafeguardingException\EnsureCanManageSafeguardingExceptionAction;
use App\Actions\User\AnonymiseClosedAccountAction;
use App\Models\SafeguardingException;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

// SCRUM-381: a safeguarding exception as administrators see it -- the audit trail, visible to every
// administrator. Only the super administrator may renew or end one (`canManage`, per row; the
// actions re-check). SCRUM-382 review (SCRUM-362's rule for reports): an administrator who counsels
// the minor, or who asked for this exception as their counsellor, sees only that it exists -- not who
// the minor is, nor the reasons. Expects `ward` and `request` loaded.
/** @mixin SafeguardingException */
class AdminSafeguardingExceptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $viewer = $request->user();

        if ($viewer && EnsureCanManageSafeguardingExceptionAction::new()->involves($viewer, $this->ward, $this->resource->request)) {
            return [
                'id' => $this->id,
                'status' => $this->status(),
                'involved' => true,
                'startsAt' => $this->starts_at,
                'canManage' => false,
            ];
        }

        return [
            'id' => $this->id,
            'status' => $this->status(),
            'involved' => false,
            'ward' => [
                'id' => $this->ward?->id,
                'name' => $this->ward?->name ?? AnonymiseClosedAccountAction::CLOSED_ACCOUNT_NAME,
                'username' => $this->ward?->username,
            ],
            'reason' => $this->reason,
            'startsAt' => $this->starts_at,
            'endsAt' => $this->ends_at,
            'grantedBy' => $this->grantedBy?->name ?? 'a deleted account',
            'requestId' => $this->request_id,
            'previousId' => $this->previous_id,
            'ended' => $this->ended_at ? [
                'at' => $this->ended_at,
                'by' => $this->endedBy?->name ?? 'a deleted account',
                'kind' => $this->end_kind,
                'reason' => $this->end_reason,
            ] : null,
            'remindedAt' => $this->expiry_reminded_at,
            // Not involved (above), so a super administrator here doesn't counsel the minor.
            'canManage' => (bool) $viewer?->isSuperAdmin() && $this->isActive(),
        ];
    }
}
