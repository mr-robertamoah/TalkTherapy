<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

// TT-4.10e/SCRUM-294: used only by GetRequestResourceAction's `RequestTypeEnum::dobChange`
// branch, i.e. the accept/reject response payload -- RequestService::getRequests() (the
// personal requests list) always uses the generic RequestResource instead, which has its own
// independent dobChange handling (see RequestResource::getTo()/getFor()). `to` is genuinely
// nullable here (any admin may respond when the target has no active guardian, mirroring
// RefundRequestResource's identical null-`to` handling), so it's resolved explicitly rather
// than reused through UserMiniResource's own "null means deleted" convention, which would
// misrepresent "not yet assigned to anyone" as "the guardian's account was deleted."
class DobChangeRequestResource extends JsonResource
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
            'to' => $this->to ? new UserMiniResource($this->to) : null,
            'for' => new UserMiniResource($this->for),
            'newDob' => $this->data['newDob'] ?? null,
            'priorDob' => $this->data['priorDob'] ?? null,
            'createdAt' => $this->created_at->diffForHumans(),
        ];
    }
}
