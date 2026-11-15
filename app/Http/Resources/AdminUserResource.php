<?php

namespace App\Http\Resources;

use App\Enums\AdministratorTypeEnum;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminUserResource extends JsonResource
{
    // SCRUM-430: whether the account is suspended -- null when not loaded, and (security review) for an administrator
    // involved in that suspension: the suspensions list keeps who was suspended from them (AdminSuspensionResource).
    private function accountSuspendedFor(?User $viewer): ?bool
    {
        if (! array_key_exists('account_suspended', $this->getAttributes())) {
            return null;
        }

        if (! $this->account_suspended) {
            return false;
        }

        if (! $viewer || ! $this->relationLoaded('suspensions') || $this->suspensions->contains(fn ($suspension) => $suspension->involves($viewer))) {
            return null;
        }

        return true;
    }

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'firstName' => $this->firstName,
            'lastName' => $this->lastName,
            'gender' => $this->gender,
            'country' => $this->country,
            'username' => $this->username,
            'otherNames' => $this->otherNames,
            'email' => $this->email,
            'emailVerified' => (bool) $this->email_verified_at,
            'dob' => $this->dob,
            'age' => $this->age,
            'createdAt' => $this->created_at,
            // SCRUM-366: links an administrator to their read log. Only when loaded (no query per row).
            'isAdmin' => $this->relationLoaded('administrator') ? (bool) $this->administrator : null,
            // SCRUM-430: one role (super admin, admin, counsellor or user) and whether the account is suspended -- only
            // when the list loaded them (UserService::getUsersForAdmin), never a query per row.
            'role' => $this->relationLoaded('administrator') && array_key_exists('counsellor_exists', $this->getAttributes()) ? match (true) {
                $this->administrator?->type === AdministratorTypeEnum::super->value => 'super admin',
                (bool) $this->administrator => 'admin',
                (bool) $this->counsellor_exists => 'counsellor',
                default => 'user',
            } : null,
            'accountSuspended' => $this->accountSuspendedFor($request->user()),
            // SCRUM-193: shown beside the username in the admin users list.
            'avatar' => $this->avatar?->url,
            // SCRUM-34c: whether they have two-factor sign-in on -- for a super administrator only (they can reset it).
            'twoFactorEnabled' => $request->user()?->isSuperAdmin() ? $this->hasTwoFactorEnabled() : null,
            // SCRUM-427: a new address waiting for its owner to confirm it (super administrators only).
            'pendingEmail' => $request->user()?->isSuperAdmin() ? $this->pending_email : null,
            // SCRUM-424: when a scheduled reset applies (super administrators only; loaded, never a query per row).
            'twoFactorResetDueAt' => $request->user()?->isSuperAdmin() && $this->relationLoaded('pendingTwoFactorReset') ? $this->pendingTwoFactorReset?->due_at : null,
        ];
    }
}
