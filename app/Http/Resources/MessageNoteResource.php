<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MessageNoteResource extends JsonResource
{
    // Deliberately its own resource, still used directly by MessageNoteController's own CRUD
    // responses. SCRUM-203/TT-2.3b later also embeds this same resource as MessageResource's
    // `note` field -- that field only ever gets populated for a counsellor viewer, because
    // MessageService explicitly eager-loads `notes` scoped to `counsellor_id = $viewer's own
    // counsellor id` (see MessageService::getSessionMessages/getDiscussionMessages/
    // getTherapyTopicMessages). The isolation guarantee now lives in that per-call-site scoping
    // and its test coverage (MessageNoteUiWiringTest.php), not in "nothing else ever touches
    // notes".
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
