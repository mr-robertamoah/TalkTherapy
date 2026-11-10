<?php

namespace App\Actions\Counsellor;

use App\Actions\Action;
use App\DTOs\DeleteCounsellorDTO;
use App\Enums\CounsellorGroupTherapyStateEnum;
use App\Enums\OrganizationCounsellorStatusEnum;
use App\Enums\TherapyStatusEnum;
use App\Exceptions\CannotDeleteCounsellorException;

class EnsureCanDeleteCounsellorAction extends Action
{
    public function execute(DeleteCounsellorDTO $deleteCounsellorDTO)
    {
        $counsellor = $deleteCounsellorDTO->counsellor;
        $user = $deleteCounsellorDTO->user;

        // Authorization is checked alone, first, with a deliberately generic message -- SCRUM-134.
        // Admin-triggered deletion requires isSuperAdmin() (not just isAdmin()), matching
        // UserService::deleteUserByAdmin()'s equivalent destructive-action gate. The state checks
        // below give specific messages, which would otherwise let an unauthorized caller probe a
        // counsellor's internal state (an in-session therapy? a pending affiliation?) just from
        // which error comes back for someone else's counsellorId -- so they must never run before
        // authorization succeeds.
        if (! ($user?->isSuperAdmin() || $user?->is($counsellor?->user))) {
            throw new CannotDeleteCounsellorException('You are either not authorized to delete this counsellor account or there are some sessions to finish.', 422);
        }

        if ($counsellor->hasPendingSessions()) {
            throw new CannotDeleteCounsellorException('You have sessions that need to be completed or cancelled before you can delete this counsellor account.', 422);
        }

        if ($counsellor->therapies()->where('status', TherapyStatusEnum::in_session->value)->exists()) {
            throw new CannotDeleteCounsellorException('You have a therapy that is currently in session. Please end it before deleting this counsellor account.', 422);
        }

        if ($counsellor->groupTherapies()->wherePivot('state', CounsellorGroupTherapyStateEnum::active->value)->exists()) {
            throw new CannotDeleteCounsellorException('You are still an active counsellor on a group therapy. Please leave it before deleting this counsellor account.', 422);
        }

        // Only requests awaiting this counsellor's own decision block deletion -- requests they
        // themselves sent (verification, organization applications, discussion invites) become
        // moot on deletion and are auto-declined by DeleteCounsellorAction instead.
        if ($counsellor->receivedRequests()->wherePending()->exists()) {
            throw new CannotDeleteCounsellorException('You have pending requests awaiting your decision. Please respond to them before deleting this counsellor account.', 422);
        }

        if ($counsellor->organizationCounsellors()->where('status', OrganizationCounsellorStatusEnum::active->value)->exists()) {
            throw new CannotDeleteCounsellorException('You have an active organization affiliation. Please end it before deleting this counsellor account.', 422);
        }
    }
}
