<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MessageNoteResource extends JsonResource
{
    // Deliberately its own resource, never a field tacked onto MessageResource -- same rationale
    // as SessionNoteResource: "notes never reach the client" only holds if nothing ever
    // eager-loads a notes relation into a resource other than this one's own controller path.
    // No isEditable field -- unlike SessionNoteResource, a message note has no time-based edit
    // window to report (see decision-log.md, SCRUM-22/TT-2.3).
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'content' => $this->content,
            'createdAt' => $this->created_at,
            'updatedAt' => $this->updated_at,
        ];
    }
}
