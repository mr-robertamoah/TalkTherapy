<?php

namespace App\Http\Resources;

use App\Enums\ConstantsEnum;
use App\Enums\RequestTypeEnum;
use App\Models\Discussion;
use App\Models\GroupTherapy;
use App\Models\Organization;
use App\Models\OrganizationCounsellor;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RequestResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $viewer = $request->user();

        return [
            'id' => $this->id,
            'from' => $this->getFrom($viewer),
            'for' => $this->getFor(),
            'to' => $this->getTo($viewer),
            'status' => $this->status,
            'type' => $this->type,
            'createdAt' => $this->created_at->diffForHumans(),
        ];
    }

    private function getFrom(?User $viewer)
    {
        // SCRUM-146: from/to being an Organization is only possible for the org-context request
        // types (OrganizationRequestResource, via GetRequestResourceAction, handles those in
        // full elsewhere) -- this generic resource just needs to not throw when one of them
        // reaches the un-dispatched requests-list endpoint (RequestService::getRequests()).
        if ($this->from_type == Organization::class) {
            return new OrganizationMiniResource($this->from);
        }

        if ($this->from_type != User::class) {
            return new CounsellorMiniResource($this->from);
        }

        // A group-therapy membership request's `from` is the requesting user, who may have
        // chosen (or be forced into, by the group's own anonymous flag) anonymity -- unmasked
        // here would leak the exact identity this ticket's whole feature is meant to protect,
        // for anyone who can see the request (the group creator, or the requester themselves).
        // Only mask for someone other than the requester -- they must still see their own name.
        if ($this->type == RequestTypeEnum::groupTherapyMembership->value) {
            $isAnonymous = $this->for?->anonymous || (bool) ($this->data['anonymous'] ?? false);

            if ($isAnonymous && ! $this->from?->is($viewer)) {
                return ['id' => $this->from?->id, 'fullName' => ConstantsEnum::anonymousUserLabel->value, 'isUser' => true];
            }
        }

        if ($this->isOrgMemberFlowUser($this->from, $viewer)) {
            return $this->narrowUserProjection($this->from);
        }

        return new UserMiniResource($this->from);
    }

    private function getTo(?User $viewer)
    {
        if ($this->to_type == Organization::class) {
            return new OrganizationMiniResource($this->to);
        }

        if ($this->to_type != User::class) {
            return new CounsellorMiniResource($this->to);
        }

        // For a group-therapy membership request, `to` is always the group's creator -- mask
        // them the same way GroupTherapyResource/GroupTherapyMiniResource already do for an
        // anonymous group (group-level flag only; the creator has no personal per-request
        // anonymity choice the way the requester in getFrom() above does), except to the
        // creator's own view of their own request.
        if ($this->type == RequestTypeEnum::groupTherapyMembership->value) {
            if ($this->for?->anonymous && ! $this->to?->is($viewer)) {
                return ['id' => $this->to?->id, 'fullName' => ConstantsEnum::anonymousUserLabel->value, 'isUser' => true];
            }
        }

        if ($this->isOrgMemberFlowUser($this->to, $viewer)) {
            return $this->narrowUserProjection($this->to);
        }

        return new UserMiniResource($this->to);
    }

    // SCRUM-162 security review: TT-6.6d's org-scoped request queue newly surfaces
    // organizationMemberInvite/organizationMemberApplication rows (whose from/to is an ordinary
    // User the org admin has no other relationship with) to this generic resource, via
    // RequestService::getRequests(). The full UserMiniResource (gender/country/dob) would reopen
    // the exact PII-enumeration oracle SCRUM-124 already closed for
    // OrganizationMemberController::invite()'s own response -- an org admin could invite/probe
    // arbitrary user ids and read their PII back here. Narrowed the same way, except for the
    // user's own view of their own request.
    private function isOrgMemberFlowUser(?User $user, ?User $viewer): bool
    {
        return in_array($this->type, [
            RequestTypeEnum::organizationMemberInvite->value,
            RequestTypeEnum::organizationMemberApplication->value,
        ]) && ! $user?->is($viewer);
    }

    private function narrowUserProjection(?User $user): array
    {
        return ['id' => $user?->id, 'fullName' => $user?->name, 'username' => $user?->username, 'isUser' => true];
    }

    private function getFor()
    {
        if ($this->type == RequestTypeEnum::therapy->value) {
            return new TherapyMiniResource($this->for);
        }

        if ($this->for_type == GroupTherapy::class) {
            return new GroupTherapyMiniResource($this->for);
        }

        if ($this->for_type == User::class) {
            return new UserMiniResource($this->for);
        }

        if ($this->for_type == Discussion::class) {
            return new DiscussionMiniResource($this->for);
        }

        if ($this->for_type == Organization::class) {
            return new OrganizationMiniResource($this->for);
        }

        if ($this->for_type == OrganizationCounsellor::class) {
            return [
                'id' => $this->for?->id,
                'organization' => new OrganizationMiniResource($this->for?->organization),
                'counsellor' => new CounsellorMiniResource($this->for?->counsellor),
            ];
        }

        return new CounsellorMiniResource($this->for);
    }
}
