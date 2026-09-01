<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SessionNoteResource extends JsonResource
{
    // Deliberately its own resource, never a field tacked onto SessionResource -- see the
    // session_notes migration's comment and decision-log.md (SCRUM-197): "notes never reach the
    // client" only holds if nothing ever eager-loads a notes relation into a resource other than
    // this one's own controller path.
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
