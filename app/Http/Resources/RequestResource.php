<?php

namespace App\Http\Resources;

use App\Enums\RequestTypeEnum;
use App\Models\Discussion;
use App\Models\GroupTherapy;
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
                return ['id' => $this->from?->id, 'fullName' => 'Client (Anonymous User)', 'isUser' => true];
            }
        }

        return new UserMiniResource($this->from);
    }

    private function getTo(?User $viewer)
    {
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
                return ['id' => $this->to?->id, 'fullName' => 'Client (Anonymous User)', 'isUser' => true];
            }
        }

        return new UserMiniResource($this->to);
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

        return new CounsellorMiniResource($this->for);
    }
}
