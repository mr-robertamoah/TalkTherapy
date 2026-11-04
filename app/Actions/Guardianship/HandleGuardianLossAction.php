<?php

namespace App\Actions\Guardianship;

use App\Actions\Action;
use App\Actions\Video\GetLivePersonVideoParticipationsAction;
use App\Events\GuardianAccessChangedEvent;
use App\Events\TherapyAccessChangedEvent;
use App\Jobs\RemovePausedMinorFromVideoJob;
use App\Models\Therapy;
use App\Models\User;
use App\Models\VideoSessionParticipant;
use App\Notifications\ClientAccessPausedNotification;
use App\Notifications\GuardianAccessPausedNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

// SCRUM-288: a minor has just been left with no active guardian (RemoveGuardianFromWardAction,
// after its commit). Their therapies stay open and their sessions stay booked (Q2); every write is
// refused by the live check (EnsureMinorHasActiveGuardianAction). What this does is reach outside the
// database, each step best effort and logged (like RemoveSuspendedPersonFromVideoAction):
//  - takes them out of live calls: a 1:1 room is ended for both (its counsellor is read-only too,
//    Q5; the session's status is left alone), and in a group only they are removed. Each under the
//    person's join lock, re-checked inside, so a join can't land in between; retried from the queue
//    when it can't be done now (RemovePausedMinorFromVideoJob);
//  - tells their open pages, and the counsellor's page of each open 1:1 (never a group channel);
//  - tells them (neutrally, with where to find urgent help) and each counsellor of an open 1:1
//    (with the client's name as that therapy shows it -- never anything about the family).
// Nothing happens if they have a guardian again by the time this runs.
class HandleGuardianLossAction extends Action
{
    public function execute(User $ward, string $trigger): void
    {
        $ward = User::query()->find($ward->id);

        if (! $ward || ! $ward->isPausedForMissingGuardian(fresh: true)) {
            return;
        }

        $open = GetWardOpenTherapiesAction::new()->execute($ward);
        $removedFrom = $this->removeFromVideo($ward);

        if (GetWardOpenTherapiesAction::new()->hasAny($open)) {
            $this->attempt(fn () => GuardianAccessChangedEvent::dispatch($ward->id, GuardianAccessChangedEvent::PAUSED), $ward, 'broadcast');
            $open['therapies']->each(fn (Therapy $therapy) => $this->attempt(fn () => TherapyAccessChangedEvent::dispatch($therapy->id), $ward, 'broadcast'));

            $this->attempt(fn () => $ward->notify(new GuardianAccessPausedNotification), $ward, 'notify');
            $open['therapies']
                ->filter(fn (Therapy $therapy) => $therapy->counsellor?->user)
                ->each(fn (Therapy $therapy) => $this->attempt(
                    fn () => Notification::send($therapy->counsellor->user, new ClientAccessPausedNotification($therapy, $therapy->displayNameOf($ward))),
                    $ward,
                    'notify',
                ));
        }

        Log::info('guardian_access.paused', [
            'ward' => $ward->id,
            'trigger' => $trigger,
            'therapies' => $open['therapies']->count(),
            'groups' => $open['groups']->count(),
            'video_rooms_left' => $removedFrom,
        ]);
    }

    // How many rooms they were taken out of now. A room it couldn't do now (their join lock was
    // busy, or a step failed) is retried from the queue (RemovePausedMinorFromVideoJob).
    private function removeFromVideo(User $ward): int
    {
        return GetLivePersonVideoParticipationsAction::new()->execute($ward)
            ->map(fn (VideoSessionParticipant $participant) => (int) $participant->videoSession->session_id)
            ->unique()
            ->filter(function (int $sessionId) use ($ward) {
                try {
                    return RemovePausedMinorFromVideoJob::remove($ward, $sessionId);
                } catch (Throwable $th) {
                    Log::warning('Could not take a paused minor out of a video call yet; retrying from the queue.', [
                        'ward' => $ward->id, 'session' => $sessionId, 'error' => $th->getMessage(),
                    ]);
                    $this->attempt(fn () => RemovePausedMinorFromVideoJob::dispatch($sessionId, $ward->id)->delay(now()->addSeconds(10)), $ward, 'video-retry', ['session' => $sessionId]);

                    return false;
                }
            })
            ->count();
    }

    private function attempt(callable $step, User $ward, string $what, array $context = []): void
    {
        try {
            $step();
        } catch (Throwable $th) {
            Log::warning('Could not complete a step of pausing a minor\'s therapies for a missing guardian.', [
                'ward' => $ward->id,
                'step' => $what,
                ...$context,
                'error' => $th->getMessage(),
            ]);
        }
    }
}
