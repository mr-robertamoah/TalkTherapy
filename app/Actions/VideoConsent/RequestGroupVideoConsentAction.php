<?php

namespace App\Actions\VideoConsent;

use App\Actions\Action;
use App\Actions\Video\EnsureVideoIsAvailableForSessionAction;
use App\Exceptions\VideoConsentException;
use App\Models\GroupTherapy;
use App\Models\User;
use App\Notifications\GroupVideoConsentRequestedNotification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Throwable;

// SCRUM-313: a minor in a group asks their guardians for video consent. Every guardian they still have is told, once
// per group in a while (asking again sooner says it's already been asked). The notice doesn't name the group's
// other members; it links to the guardian's own list of their ward's groups, where they can answer.
class RequestGroupVideoConsentAction extends Action
{
    public const ASK_AGAIN_AFTER_HOURS = 6;

    // Whether the guardians were told now (false: they already were, recently).
    public function execute(User $ward, GroupTherapy $group): bool
    {
        if (! $group->isParticipant($ward) || ! EnsureVideoIsAvailableForSessionAction::new()->groupVideoNeedsGuardianConsent($group, $ward)) {
            throw new VideoConsentException('You don\'t need a guardian\'s consent for video in this group.', 422);
        }

        $guardians = $ward->activeGuardianships()->with('guardian')->get()->pluck('guardian')->filter();

        if ($guardians->isEmpty()) {
            throw new VideoConsentException('You have no parent or guardian on your account to ask.', 422);
        }

        if (! Cache::add("group-video-consent-asked:{$group->id}:{$ward->id}", true, now()->addHours(self::ASK_AGAIN_AFTER_HOURS))) {
            return false;
        }

        try {
            Notification::send($guardians, new GroupVideoConsentRequestedNotification($group, $ward));
        } catch (Throwable $exception) {
            // Nobody was told: they can ask again straight away.
            Cache::forget("group-video-consent-asked:{$group->id}:{$ward->id}");

            throw $exception;
        }

        return true;
    }
}
