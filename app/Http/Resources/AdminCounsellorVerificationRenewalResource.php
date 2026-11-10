<?php

namespace App\Http\Resources;

use App\Support\CounsellorVerification;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

// SCRUM-31: a counsellor in the admins' renewals list -- due to re-verify, ended or revoked.
class AdminCounsellorVerificationRenewalResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->getName(),
            'username' => $this->user?->username,
            'avatar' => $this->avatar?->url,
            'verification' => CounsellorVerification::projection($this->resource, $request->user()),
            'hasPendingRequest' => (bool) $this->pending_verification_request_exists,
        ];
    }
}
