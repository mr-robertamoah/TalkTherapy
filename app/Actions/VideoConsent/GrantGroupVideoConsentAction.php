<?php

namespace App\Actions\VideoConsent;

use App\Actions\Action;
use App\Actions\Video\EnsureVideoIsAvailableForSessionAction;
use App\Enums\VideoConsentLevelEnum;
use App\Enums\VideoConsentRevocationReasonEnum;
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
// SCRUM-329: at the level the guardian chooses -- watch and listen only, or speak too (an under-18 who is anonymous in
// the group watches and listens whatever it says, EnsureMemberMayBeGrantedSpeakingAction). An existing valid grant for
// the same scope is returned as it is; ChangeGroupVideoConsentLevelAction changes its level.
class GrantGroupVideoConsentAction extends Action
{
    public const NOT_YOUR_WARD = 'You can only give video consent for your own ward in this group.';

    public function execute(User $guardian, User $ward, GroupTherapy|Session $consentable, VideoConsentLevelEnum $level): VideoConsent
    {
        $group = $consentable instanceof GroupTherapy ? $consentable : $consentable->for;

        if (! $group instanceof GroupTherapy) {
            throw new VideoConsentException('Video consent here is only for a group therapy.', 422);
        }

        $this->ensureMayGrant($guardian, $ward, $group);

        return DB::transaction(function () use ($guardian, $ward, $consentable, $group, $level) {
            // Leaving locks the ward's row and lapses their grants for the group: taken here first (the same order) and
            // re-checked, so a grant can't land just after they left and count again if they come back.
            User::query()->whereKey($ward->id)->lockForUpdate()->first();
            $this->ensureMayGrant($guardian, $ward, $group->fresh());

            // One valid grant per ward and scope: the scope's row serialises two guardians granting at once.
            $consentable::query()->lockForUpdate()->find($consentable->id);

            // Only a grant the join gate counts (from a guardian the ward still has) makes a new one unnecessary.
            $existing = VideoConsent::query()->whereValidFor($ward->id, $consentable::class, $consentable->id)->fromActiveGuardianOf($ward)->first();

            if ($existing) {
                return $existing;
            }

            // One open grant per ward and scope: one from a guardian who no longer counts is replaced, not left to count
            // again (at whatever level it said) if they do.
            VideoConsent::query()->whereValidFor($ward->id, $consentable::class, $consentable->id)
                ->update(['revoked_at' => now(), 'revocation_reason' => VideoConsentRevocationReasonEnum::superseded->value]);

            return VideoConsent::query()->create([
                'ward_id' => $ward->id,
                'guardian_id' => $guardian->id,
                'consentable_type' => $consentable::class,
                'consentable_id' => $consentable->id,
                'level' => $level->value,
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
