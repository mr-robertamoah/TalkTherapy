<?php

namespace App\Actions\User;

use App\Actions\Action;
use App\Actions\Guardianship\RemoveGuardianFromWardAction;
use App\DTOs\GetGuardianshipDTO;
use App\Models\User;
use App\Notifications\GuardianshipRemovedNotification;

class DeleteGuardianshipAction extends Action
{
    public function execute(GetGuardianshipDTO $getGuardianshipDTO)
    {
        $guardianship = $getGuardianshipDTO->guardianship;
        // A deleted (soft-deleted) ward or guardian isn't loaded by the relations.
        $ward = User::withTrashed()->find($guardianship->ward_id);
        $guardian = User::withTrashed()->find($guardianship->guardian_id);

        if (! $ward || ! $guardian) {
            $guardianship->delete();

            return;
        }

        // SCRUM-288: deletes the row, lapses this guardian's own video consents for the ward
        // (TT-3.1e-c/SCRUM-282) and pauses a minor it leaves with no active guardian. A deleted
        // ward only has the row removed.
        RemoveGuardianFromWardAction::new()->execute($guardian, $ward, RemoveGuardianFromWardAction::GUARDIANSHIP_REMOVED);

        if (! $ward->trashed()) {
            $ward->notify(new GuardianshipRemovedNotification($guardian));
        }
    }
}
