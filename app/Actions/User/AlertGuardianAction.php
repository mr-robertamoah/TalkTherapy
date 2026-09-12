<?php

namespace App\Actions\User;

use App\Actions\Action;
use App\DTOs\GuardianAlertDTO;
use App\Models\User;
use Illuminate\Support\Facades\Notification;

class AlertGuardianAction extends Action
{
    public function execute(GuardianAlertDTO $guardianAlertDTO)
    {
        // TT-4.10b/SCRUM-291: was a bare `$guardianAlertDTO->user->isAdult()` live re-check --
        // now prefers the linked record's stable client_was_minor_at_creation snapshot
        // (TT-4.10a) when the caller supplied one, closing the self-editable-dob bypass
        // SCRUM-287 found. Falls back to the live check when no record was supplied (a caller
        // this DTO's own `for` doesn't apply to) or the record's snapshot is itself null (not
        // applicable -- see clientIsMinor()'s own reasoning).
        $isMinor = $guardianAlertDTO->for
            ? $guardianAlertDTO->for->clientIsMinor()
            : ! $guardianAlertDTO->user->isAdult();

        if (! $isMinor) {
            return;
        }

        $guardians = User::query()
            ->whereWard($guardianAlertDTO->user)
            ->get();

        if (! $guardians?->count()) {
            return;
        }

        Notification::send($guardians, $guardianAlertDTO->notification);
    }
}
