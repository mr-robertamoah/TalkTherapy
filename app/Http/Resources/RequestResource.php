<?php

namespace App\Http\Resources;

use App\Enums\ConstantsEnum;
use App\Enums\RequestTypeEnum;
use App\Models\Discussion;
use App\Models\GroupTherapy;
use App\Models\Organization;
use App\Models\OrganizationCounsellor;
use App\Models\Transaction;
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
            // SCRUM-208 (TT-2.5c): only meaningful for a session-schedule proposal -- every other
            // type in this resource has no proposal terms/round/stale-state to show.
            // Explicitly whitelisted, not a raw spread of `data` -- mirrors
            // OrganizationRequestResource's identical `proposedTerms` precedent (SCRUM-150/PR #89):
            // that column also carries `proposedById`/`sessionId` (internal ids used only for
            // server-side attribution), which must never reach either negotiating party.
            'proposal' => $this->when(
                $this->type === RequestTypeEnum::sessionScheduleProposal->value,
                fn () => [
                    'startTime' => $this->data['startTime'] ?? null,
                    'endTime' => $this->data['endTime'] ?? null,
                    'name' => $this->data['name'] ?? null,
                    'about' => $this->data['about'] ?? null,
                    'type' => $this->data['type'] ?? null,
                    'paymentType' => $this->data['paymentType'] ?? null,
                    'staleReason' => $this->data['staleReason'] ?? null,
                    'reason' => $this->data['reason'] ?? null,
                ]
            ),
            // TT-4.10e/SCRUM-294: only meaningful for a dobChange request -- mirrors
            // `proposal` above's identical "explicitly whitelisted, not a raw spread of `data`"
            // precedent (nothing else in `data` needs to reach either party for this type).
            'dobChange' => $this->when(
                $this->type === RequestTypeEnum::dobChange->value,
                fn () => [
                    'newDob' => $this->data['newDob'] ?? null,
                    'priorDob' => $this->data['priorDob'] ?? null,
                ]
            ),
            'round' => $this->when(! is_null($this->round), $this->round),
            'expiresAt' => $this->when(! is_null($this->expires_at), fn () => $this->expires_at?->diffForHumans()),
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

        // TT-4.10e/SCRUM-294 security review: for a self-service dob edit, `from` and `for` are
        // the same user (the ward editing their own dob) -- narrowing `getFor()` alone still let
        // this branch broadcast the identical PII (gender/country/dob) to every admin via `from`.
        // Shares `isNullToDobChange()` with getTo()/getFor() so a future similar type can't repeat
        // this asymmetry by only narrowing one of the three fields.
        if ($this->isNullToDobChange()) {
            return $this->narrowUserProjection($this->from);
        }

        if ($this->isOrgMemberFlowUser($this->from, $viewer)) {
            return $this->narrowUserProjection($this->from);
        }

        return new UserMiniResource($this->from);
    }

    private function getTo(?User $viewer)
    {
        // TT-4.10e/SCRUM-294: a dobChange request's `to` is genuinely nullable ("any admin may
        // respond" when no guardian exists, mirroring refund's identical null-`to` shape) -- the
        // generic `$this->to_type != User::class` branch below would otherwise call
        // CounsellorMiniResource(null), which resolves to `{deleted: true, ...}` and
        // misrepresents "not yet assigned to anyone" as "the guardian's account was deleted."
        if ($this->isNullToDobChange()) {
            return null;
        }

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

    // TT-4.10e/SCRUM-294 security review: shared by getFrom()/getTo()/getFor() so the null-`to`
    // dobChange narrowing can't be applied to only one of the three fields by accident.
    private function isNullToDobChange(): bool
    {
        return $this->type === RequestTypeEnum::dobChange->value && is_null($this->to_type);
    }

    private function getFor()
    {
        // SCRUM-206 (TT-2.5a): a session-schedule proposal's `for` is also a Therapy directly,
        // same shape as a `therapy` (assistance) request.
        if (in_array($this->type, [RequestTypeEnum::therapy->value, RequestTypeEnum::sessionScheduleProposal->value])) {
            return new TherapyMiniResource($this->for);
        }

        if ($this->for_type == GroupTherapy::class) {
            return new GroupTherapyMiniResource($this->for);
        }

        if ($this->for_type == User::class) {
            // TT-4.10e/SCRUM-294 security review: a null-`to` dobChange request is visible to
            // *every* admin in their personal requests list (RequestService::getRequests()'s
            // admin-visibility branch), not just whichever one eventually responds -- the full
            // UserMiniResource (gender/country/dob) would broadcast a minor's PII to every admin
            // who merely opens their requests list. Narrowed the same way isOrgMemberFlowUser()
            // already narrows from/to for a viewer with no established relationship to the user;
            // `dob` specifically is redundant anyway since `priorDob` above already carries it.
            if ($this->isNullToDobChange()) {
                return $this->narrowUserProjection($this->for);
            }

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

        // TT-7.7c/SCRUM-251: a refund request's `for` is a Transaction -- previously fell through
        // to CounsellorMiniResource below, which has no matching fields on a Transaction and
        // silently rendered garbage/nulls for a client's own "my requests" listing.
        if ($this->for_type == Transaction::class) {
            return [
                'id' => $this->for?->id,
                'reference' => $this->for?->reference,
                'amount' => $this->for?->amount,
                'currency' => $this->for?->currency,
            ];
        }

        return new CounsellorMiniResource($this->for);
    }
}
