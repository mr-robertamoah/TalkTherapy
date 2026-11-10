<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

// SCRUM-31: one row of a counsellor's verification history, for administrators only. Licences by id
// (never their numbers or documents); the administrator by name; null actor means the system.
class CounsellorVerificationEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'event' => $this->event->value,
            'label' => $this->event->label(),
            'actor' => $this->actor ? ['fullName' => $this->actor->name, 'username' => $this->actor->username] : null,
            'requestId' => $this->request_id,
            'licenseIds' => $this->license_ids ?? [],
            'reason' => $this->reason,
            'expiresAt' => $this->expires_at?->toJSON(),
            'windowDays' => $this->window_days,
            'createdAt' => $this->created_at?->toJSON(),
        ];
    }
}
