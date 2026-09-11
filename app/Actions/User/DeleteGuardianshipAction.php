<?php

namespace App\Actions\User;

use App\Actions\Action;
use App\Actions\VideoConsent\InvalidateVideoConsentAction;
use App\DTOs\GetGuardianshipDTO;
use App\Enums\VideoConsentRevocationReasonEnum;
use App\Models\VideoConsent;
use App\Notifications\GuardianshipRemovedNotification;

class DeleteGuardianshipAction extends Action
{
    public function execute(GetGuardianshipDTO $getGuardianshipDTO)
    {
        $guardianship = $getGuardianshipDTO->guardianship;

        $guardianship->delete();

        $guardianship->ward->notify(
            new GuardianshipRemovedNotification($guardianship->guardian)
        );

        // TT-3.1e-c/SCRUM-282: this guardian's own still-valid video consent grants for this ward
        // lapse with the guardianship itself -- scoped to ONLY this guardian_id/ward_id pair, so a
        // valid grant another of the ward's guardians made is untouched. Goes through the same
        // shared invalidation path RevokeVideoConsentAction uses, so an active call under a
        // lapsed grant ends immediately here too, not just on the next join attempt.
        VideoConsent::query()
            ->where('ward_id', $guardianship->ward_id)
            ->where('guardian_id', $guardianship->guardian_id)
            ->whereNull('revoked_at')
            ->get()
            ->each(fn (VideoConsent $consent) => InvalidateVideoConsentAction::new()->execute(
                $consent,
                VideoConsentRevocationReasonEnum::guardianship_removed,
            ));
    }
}
