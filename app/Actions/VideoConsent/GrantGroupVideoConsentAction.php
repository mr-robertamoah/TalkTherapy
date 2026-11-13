<?php

namespace App\Actions\VideoConsent;

use App\Actions\Action;
use App\Actions\Video\EnsureVideoIsAvailableForSessionAction;
use App\Enums\VideoConsentLevelEnum;
use App\Exceptions\VideoConsentException;
use App\Models\GroupTherapy;
use App\Models\Session;
use App\Models\User;
use App\Models\VideoConsent;
use Illuminate\Support\Facades\DB;

// SCRUM-313: a guardian's consent for their ward to take part in a group's video -- for the whole group, or for one
// of its sessions (GetGroupVideoConsentSessionAction). Any one of the ward's guardians may give it, as for a
// one-to-one therapy. The ward must be one of the group's members (its user creator or a current member) and still
// need consent (a minor, not one of its counsellors); the guardian one they still have.
//
// SPEAK level for now -- consent to take part, as one-to-one consent has always been. SCRUM-329 lets the guardian
// choose watch only; until then speaking stays blocked for minors in a group by its own interim check, and an
// ordinary member joins receive-only regardless.
class GrantGroupVideoConsentAction extends Action
{
    public const NOT_YOUR_WARD = 'You can only give video consent for your own ward in this group.';

    public function execute(User $guardian, User $ward, GroupTherapy|Session $consentable): VideoConsent
    {
        $group = $consentable instanceof GroupTherapy ? $consentable : $consentable->for;

        if (! $group instanceof GroupTherapy) {
            throw new VideoConsentException('Video consent here is only for a group therapy.', 422);
        }

        $this->ensureMayGrant($guardian, $ward, $group);

        return DB::transaction(function () use ($guardian, $ward, $consentable, $group) {
            // Leaving locks the ward's row and lapses their grants for the group: taken here first (the same order) and
            // re-checked, so a grant can't land just after they left and count again if they come back.
            User::query()->whereKey($ward->id)->lockForUpdate()->first();
            $this->ensureMayGrant($guardian, $ward, $group->fresh());

            // One valid grant per ward and scope: the scope's row serialises two guardians granting at once.
            $consentable::query()->lockForUpdate()->find($consentable->id);

            // Only a grant the join gate counts (from a guardian the ward still has) makes a new one unnecessary.
            return VideoConsent::query()->whereValidFor($ward->id, $consentable::class, $consentable->id)->fromActiveGuardianOf($ward)->first()
                ?? VideoConsent::query()->create([
                    'ward_id' => $ward->id,
                    'guardian_id' => $guardian->id,
                    'consentable_type' => $consentable::class,
                    'consentable_id' => $consentable->id,
                    'level' => VideoConsentLevelEnum::speak->value,
                    'granted_at' => now(),
                ]);
        });
    }

    public function ensureMayGrant(User $guardian, User $ward, GroupTherapy $group): void
    {
        // A member (its user creator or a current member); groupVideoNeedsGuardianConsent below leaves out its counsellors.
        if (! $group->isParticipant($ward) || ! $guardian->isActiveGuardianOf($ward)) {
            throw new VideoConsentException(self::NOT_YOUR_WARD, 422);
        }

        if (! EnsureVideoIsAvailableForSessionAction::new()->groupVideoNeedsGuardianConsent($group, $ward)) {
            throw new VideoConsentException('Video consent only applies to a member under 18.', 422);
        }
    }
}
