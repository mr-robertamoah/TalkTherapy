<?php

namespace App\Http\Resources;

use App\Enums\ConstantsEnum;
use App\Models\Counsellor;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

// SCRUM-73/383 (security review): what someone may see of a private group they can't open -- a
// holder of a usable member invitation, or a former member (EnsureUserHasAccessToTherapyAction's
// PREVIEW_*). What the group is, never who is in it: the creator is named only when they created it
// through their counsellor profile, and only the active counsellors are listed, by their public
// counsellor name (an id, for the report form) with no account details. No members, sessions, topics,
// payments or live state.
class GroupTherapyPreviewResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $counsellors = $this->activeCounsellors()
            ->map(fn (Counsellor $counsellor) => $this->counsellor($counsellor))
            ->values();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'about' => $this->about,
            'cases' => TherapyCaseResource::collection($this->cases),
            'public' => (bool) $this->public,
            'anonymous' => (bool) $this->anonymous,
            'allowAnyone' => (bool) $this->allow_anyone,
            'status' => $this->status,
            'statusLabel' => $this->getStatusLabel(),
            'paymentType' => $this->payment_type,
            'paymentData' => $this->payment_data,
            'membersCount' => $this->relationLoaded('users') ? $this->users->count() : $this->users()->count(),
            'maxUsers' => $this->max_users,
            'maxCounsellors' => $this->max_counsellors,
            'sessionsHeld' => $this->sessionsHeld,
            'createdAt' => $this->created_at,
            'addedby' => $this->addedby instanceof Counsellor
                ? $this->counsellor($this->addedby)
                : ['id' => null, 'fullName' => ConstantsEnum::anonymousUserLabel->value, 'isUser' => true],
            'counsellors' => $counsellors,
            'activeCounsellorIds' => $counsellors->pluck('id'),
            'isParticipant' => false,
            'isMember' => false,
            'isPreview' => true,
        ];
    }

    private function counsellor(Counsellor $counsellor): array
    {
        return ['id' => $counsellor->id, 'name' => $counsellor->getName(), 'isCounsellor' => true];
    }
}
