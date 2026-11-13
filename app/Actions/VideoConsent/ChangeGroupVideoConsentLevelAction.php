<?php

namespace App\Actions\VideoConsent;

use App\Actions\Action;
use App\Actions\Video\RemovePersonFromLiveCallsAction;
use App\Enums\VideoConsentLevelEnum;
use App\Enums\VideoConsentRevocationReasonEnum;
use App\Models\GroupTherapy;
use App\Models\User;
use App\Models\VideoConsent;
use App\Support\BestEffort;
use Illuminate\Support\Facades\DB;

// SCRUM-329: a guardian changes what their ward's group consent lets them do -- watch and listen only, or speak too.
// Grants are a history (one row per grant cycle, never edited), so the old one is closed (by this guardian) and a new
// one opened at the new level, together. Going down to watch only takes the floor away from them in a live call at
// once (RemovePersonFromLiveCallsAction, under their join lock); going up applies from their next join.
class ChangeGroupVideoConsentLevelAction extends Action
{
    public function execute(User $guardian, VideoConsent $consent, GroupTherapy $group, VideoConsentLevelEnum $level): VideoConsent
    {
        $ward = $consent->ward;
        GrantGroupVideoConsentAction::new()->ensureMayGrant($guardian, $ward, $group);

        if ($consent->level === $level->value) {
            return $consent;
        }

        $new = DB::transaction(function () use ($guardian, $consent, $ward, $level, $group) {
            User::query()->whereKey($ward->id)->lockForUpdate()->first();
            // Asked again under the ward's lock, as granting does (a leave or a lost guardianship in between).
            GrantGroupVideoConsentAction::new()->ensureMayGrant($guardian, $ward, $group->fresh());
            $consent->consentable_type::query()->lockForUpdate()->find($consent->consentable_id);
            $current = VideoConsent::query()->whereKey($consent->id)->whereNull('revoked_at')->first();

            if (! $current) {
                return null;
            }

            $current->update([
                'revoked_at' => now(),
                'revoked_by_guardian_id' => $guardian->id,
                'revocation_reason' => VideoConsentRevocationReasonEnum::superseded->value,
            ]);

            return VideoConsent::query()->create([
                'ward_id' => $ward->id,
                'guardian_id' => $guardian->id,
                'consentable_type' => $current->consentable_type,
                'consentable_id' => $current->consentable_id,
                'level' => $level->value,
                'granted_at' => now(),
            ]);
        });

        if ($new && $level === VideoConsentLevelEnum::watch) {
            BestEffort::attempt(
                fn () => RemovePersonFromLiveCallsAction::new()->execute($ward, RemovePersonFromLiveCallsAction::VIDEO_CONSENT_WITHDRAWN),
                'group-video-consent-watch-only',
                ['ward_id' => $ward->id, 'group_therapy_id' => $group->id],
            );
        }

        return $new ?? $consent;
    }
}
