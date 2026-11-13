<?php

namespace App\Actions\Video;

use App\Actions\Action;
use App\Actions\VideoConsent\HasValidVideoConsentForSessionAction;
use App\Enums\SpeakingPermissionRevocationReasonEnum;
use App\Enums\SuspensionScopeEnum;
use App\Jobs\RemovePersonFromLiveCallJob;
use App\Models\GroupTherapy;
use App\Models\Suspension;
use App\Models\Therapy;
use App\Models\User;
use App\Models\VideoSessionParticipant;
use App\Support\BestEffort;
use App\Support\VideoJoinLock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

// SCRUM-382: the one way the system takes a person out of the live calls they are in, after
// something has been saved that means they mustn't stay (moved here from SCRUM-363's
// RemoveSuspendedPersonFromVideoAction and SCRUM-288's guardian-loss removal). For each room:
//  - under the person's join lock for that session (VideoJoinLock), so a join can't land between
//    the check and the removal;
//  - re-read inside the lock: the person, their live row, and whether the reason still applies;
//  - by the caller's policy:
//    - GUARDIAN_LOSS: a 1:1 room is ended for both (its counsellor is read-only too, SCRUM-288 Q5;
//      the session's status is left alone), and in a group only the person is ejected. It applies
//      while they are paused for a missing guardian.
//    - SUSPENSION: the person is ejected (1:1 and group alike), from every room for an account
//      suspension, and only from rooms they are in as a counsellor for a counsellor-role one. It
//      applies while the suspension is active.
//    - NO_LONGER_MEMBER (SCRUM-73): the person is ejected from a group's room once they are no
//      longer one of its participants (they left the group). Never a 1:1 room.
//    - VIDEO_CONSENT_WITHDRAWN (SCRUM-313): a minor is ejected from a group's room once their
//      guardians' consent no longer covers that session; still covered but only to watch (SCRUM-329),
//      they lose the floor instead (taken out if the provider can't do that). Never a 1:1 room (InvalidateVideoConsentAction
//      ends a 1:1 call itself), and never the rest of the group's call.
// A room it can't do now (the lock was busy, or a step failed) is retried from the queue
// (RemovePersonFromLiveCallJob), which runs the same re-checked removal.
class RemovePersonFromLiveCallsAction extends Action
{
    public const GUARDIAN_LOSS = 'guardian_loss';

    public const SUSPENSION = 'suspension';

    public const NO_LONGER_MEMBER = 'no_longer_member';

    public const VIDEO_CONSENT_WITHDRAWN = 'video_consent_withdrawn';

    // How many rooms they were taken out of now.
    public function execute(User $person, string $reason, ?int $suspensionId = null): int
    {
        return GetLivePersonVideoParticipationsAction::new()->execute($person)
            ->map(fn (VideoSessionParticipant $participant) => (int) $participant->videoSession->session_id)
            ->unique()
            ->filter(function (int $sessionId) use ($person, $reason, $suspensionId) {
                try {
                    return $this->removeFrom($person->id, $sessionId, $reason, $suspensionId);
                } catch (Throwable $th) {
                    $context = ['person' => $person->id, 'session' => $sessionId, 'reason' => $reason];
                    Log::warning('Could not take a person out of a live call yet; retrying from the queue.', [...$context, 'error' => $th->getMessage()]);
                    // Guarded: on the sync queue the retry runs inline.
                    BestEffort::attempt(
                        fn () => RemovePersonFromLiveCallJob::dispatch($sessionId, $person->id, $reason, $suspensionId)->delay(now()->addSeconds(10)),
                        'live-call-removal-retry',
                        $context,
                    );

                    return false;
                }
            })
            ->count();
    }

    // Whether they were taken out of that session's call. Throws when it couldn't be done now (a busy
    // join lock, or a failure), for the caller to retry.
    public function removeFrom(int $personId, int $sessionId, string $reason, ?int $suspensionId = null): bool
    {
        return VideoJoinLock::run($sessionId, $personId, function () use ($personId, $sessionId, $reason, $suspensionId) {
            $person = User::query()->find($personId);
            $live = $person ? GetLivePersonVideoParticipationsAction::new()->execute($person, $sessionId)->first() : null;
            $session = $live?->videoSession?->session;

            if ($live && $session && $reason === self::VIDEO_CONSENT_WITHDRAWN && $this->mayOnlyWatch($person, $live)) {
                return $this->stopSpeaking($person, $live);
            }

            if (! $live || ! $session || ! $this->stillApplies($person, $live, $reason, $suspensionId)) {
                return false;
            }

            if ($reason === self::GUARDIAN_LOSS && ! $session->for instanceof GroupTherapy) {
                EndVideoSessionAction::new()->execute($session);
            } else {
                EjectVideoParticipantAction::new()->execute($live->videoSession, $live);
            }

            return true;
        });
    }

    // SCRUM-329: still in the call by consent, but no longer to speak (watch only now, or anonymous).
    private function mayOnlyWatch(User $person, VideoSessionParticipant $live): bool
    {
        $session = $live->videoSession->session;

        return $session->for instanceof GroupTherapy
            && EnsureVideoIsAvailableForSessionAction::new()->groupVideoNeedsGuardianConsent($session->for, $person)
            && HasValidVideoConsentForSessionAction::new()->forGroupMember($session, $person)
            && EnsureMemberMayBeGrantedSpeakingAction::new()->reason($session, $person) !== null;
    }

    // Takes the floor (and a minor creator's microphone and camera) away at the provider; if the provider can't, takes
    // them out of the call instead -- they may rejoin to watch. Whether anything was done.
    private function stopSpeaking(User $person, VideoSessionParticipant $live): bool
    {
        // Waits for a grant being given right now (GrantSpeakingPermissionAction holds this row's lock while it asks the
        // same question), so one that lands just before this is seen and closed.
        DB::transaction(fn () => VideoSessionParticipant::query()->lockForUpdate()->find($live->id));

        $revoke = RevokeSpeakingPermissionAction::new();
        $grants = $revoke->revokeAllFor($live->videoSession, $live, SpeakingPermissionRevocationReasonEnum::guardian_consent);
        $liveGrant = $grants->firstWhere('video_session_participant_id', $live->id);

        // Their floor on this join: closing it already muted them at the provider and told the call -- unless the provider
        // couldn't, which leaves it open (GUARDIAN_CONSENT never closes it unmuted), and then they're taken out.
        if ($liveGrant) {
            if ($liveGrant->fresh()->revoked_at === null) {
                EjectVideoParticipantAction::new()->execute($live->videoSession, $live);
            }

            return true;
        }

        // A creator has microphone and camera by joining, not by a grant. (A creator who joined to watch is muted again
        // -- harmless, and the safe way round if the provider fails.)
        if ($live->videoSession->session->for->isReceiveOnlyVideoParticipant($person)) {
            return $grants->isNotEmpty();
        }

        if (! $revoke->muteAtProvider($live->videoSession, $person, SpeakingPermissionRevocationReasonEnum::guardian_consent)) {
            EjectVideoParticipantAction::new()->execute($live->videoSession, $live);
        }

        return true;
    }

    private function stillApplies(User $person, VideoSessionParticipant $live, string $reason, ?int $suspensionId): bool
    {
        if ($reason === self::GUARDIAN_LOSS) {
            return $person->isPausedForMissingGuardian(fresh: true);
        }

        if ($reason === self::VIDEO_CONSENT_WITHDRAWN) {
            $session = $live->videoSession->session;

            // The join gate's own rule: an adult (or one of the group's counsellors) never needs consent, so a lapsed
            // grant from when they were a minor never takes them out.
            return $session->for instanceof GroupTherapy
                && EnsureVideoIsAvailableForSessionAction::new()->groupVideoNeedsGuardianConsent($session->for, $person)
                && ! HasValidVideoConsentForSessionAction::new()->forGroupMember($session, $person);
        }

        if ($reason === self::NO_LONGER_MEMBER) {
            $group = $live->videoSession->session->for;

            return $group instanceof GroupTherapy && ! $group->isParticipant($person);
        }

        $suspension = $suspensionId ? Suspension::query()->find($suspensionId) : null;

        if ($reason !== self::SUSPENSION || ! $suspension?->isActive() || $suspension->user_id !== $person->id) {
            return false;
        }

        if ($suspension->scope == SuspensionScopeEnum::account) {
            return true;
        }

        $therapy = $live->videoSession->session->for;
        $counsellor = $person->counsellor;

        return $counsellor && ($therapy instanceof Therapy || $therapy instanceof GroupTherapy) && $therapy->isCounsellor($counsellor);
    }
}
