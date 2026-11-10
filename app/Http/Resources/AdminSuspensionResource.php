<?php

namespace App\Http\Resources;

use App\Models\Suspension;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

// SCRUM-359: a suspension as administrators see it. An admin involved in it (Suspension::involves)
// sees only that it exists, as in the reports queue. Over an urgent help request, who asked is masked (person()).
/** @mixin Suspension */
class AdminSuspensionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $viewer = $request->user();
        $status = match (true) {
            $this->isActive() => 'active',
            ! is_null($this->lifted_at) => 'lifted',
            default => 'ended',
        };

        if ($viewer && $this->resource->involves($viewer)) {
            return ['id' => $this->id, 'status' => $status, 'involved' => true, 'startsAt' => $this->starts_at];
        }

        $isSuper = (bool) $viewer?->isSuperAdmin();

        return [
            'id' => $this->id,
            'involved' => false,
            'status' => $status,
            'person' => $this->person($viewer),
            'scope' => $this->scope->value,
            'scopeLabel' => $this->scope->label(),
            'startsAt' => $this->starts_at,
            'endsAt' => $this->ends_at,
            'publicReason' => $this->public_reason,
            'internalNote' => $this->internal_note,
            'reportId' => $this->report_id,
            'appliedBy' => $this->createdBy?->name ?? 'a deleted account',
            'lifted' => $this->lifted_at ? [
                'at' => $this->lifted_at,
                'by' => $this->liftedBy?->name ?? 'a deleted account',
                'reason' => $this->lift_reason,
            ] : null,
            'appeal' => $this->appealed_at ? [
                'text' => $this->appeal_text,
                'at' => $this->appealed_at,
                'pending' => $this->appealIsPending(),
                'decision' => $this->appeal_decision?->value,
                'note' => $this->appeal_note,
                'decidedBy' => $this->appealDecidedBy?->name,
                'decidedAt' => $this->appeal_decided_at,
            ] : null,
            'canLift' => $isSuper && $this->isActive(),
            'canDecideAppeal' => $isSuper && $this->appealIsPending() && $this->created_by !== $viewer?->id,
        ];
    }

    // SCRUM-398: a suspension over an urgent help request names who asked only to an administrator who has
    // seen it through the logged reveal -- anyone else gets the request's label, as on the report.
    private function person($viewer): array
    {
        $help = $this->report?->masksReportedIdentity() ? $this->report->helpRequest() : null;
        if ($help && ! ($viewer && $help->wasRevealedTo($viewer))) {
            return ['id' => null, 'name' => $help->personLabel(), 'username' => null, 'masked' => true];
        }

        return [
            'id' => $this->user?->id,
            'name' => $this->user?->name ?? 'a deleted account',
            'username' => $this->user?->username,
        ];
    }
}
