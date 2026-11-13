<?php

namespace App\Actions\VideoConsent;

use App\Actions\Action;
use App\Actions\Therapy\GetTherapyClientsAction;
use App\Actions\Video\EnsureVideoIsAvailableForSessionAction;
use App\Exceptions\VideoConsentException;
use App\Models\GroupTherapy;
use App\Models\Session;
use App\Models\User;
use App\Models\VideoConsent;

// SCRUM-313: what a guardian sees about a group's video consent -- for each of their own wards in it who needs consent
// (a minor member, not one of its counsellors), the valid grants for the whole group and for its current or next
// session. Nothing about any other member: a guardian of a member can't open the group (the user's decision,
// 2026-11-13), and this is all they're shown of it.
class GetGroupVideoConsentForGuardianAction extends Action
{
    public function execute(User $guardian, GroupTherapy $group): array
    {
        $gate = EnsureVideoIsAvailableForSessionAction::new();
        $wards = GetTherapyClientsAction::new()->execute($group)
            ->filter(fn (User $client) => $guardian->isActiveGuardianOf($client))
            ->values();

        if ($wards->isEmpty()) {
            throw new VideoConsentException(GrantGroupVideoConsentAction::NOT_YOUR_WARD, 422);
        }

        $session = GetGroupVideoConsentSessionAction::new()->execute($group);

        return [
            'session' => $session,
            'wards' => $wards
                ->filter(fn (User $ward) => $gate->groupVideoNeedsGuardianConsent($group, $ward))
                ->map(fn (User $ward) => [
                    'ward' => $ward,
                    'groupConsent' => $this->validGrant($ward, $group),
                    'sessionConsent' => $session ? $this->validGrant($ward, $session) : null,
                ])
                ->values()
                ->all(),
        ];
    }

    private function validGrant(User $ward, GroupTherapy|Session $consentable): ?VideoConsent
    {
        return VideoConsent::query()
            ->whereValidFor($ward->id, $consentable::class, $consentable->id)
            // What the join gate counts: a grant from a guardian the ward no longer has is shown as not given.
            ->fromActiveGuardianOf($ward)
            ->with('guardian')
            ->first();
    }
}
