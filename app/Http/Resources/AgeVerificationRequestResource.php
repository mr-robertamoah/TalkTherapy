<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

// TT-4.11c/SCRUM-304: used by GetRequestResourceAction's `RequestTypeEnum::ageVerification`
// branch -- mirrors DobChangeRequestResource's own shape (explicitly whitelisted fields, not a
// raw spread of `data`), but `to` is always null for this type (admin-only by design, no
// guardian counterpart), so it's omitted rather than modeled as nullable.
class AgeVerificationRequestResource extends JsonResource
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
            'status' => $this->status,
            'type' => $this->type,
            'from' => new UserMiniResource($this->from),
            'for' => new UserMiniResource($this->for),
            'attestation' => $this->data['attestation'] ?? null,
            // TT-4.11c/SCRUM-304: the dob snapshotted at submission time -- what an admin's
            // approval actually verifies (see RespondToAgeVerificationRequestAction), which may
            // now differ from `for.dob` if it's drifted since submission.
            'attestedDob' => $this->data['attestedDob'] ?? null,
            'hasDocument' => $this->identityDocument()->exists(),
            'createdAt' => $this->created_at->diffForHumans(),
        ];
    }
}
