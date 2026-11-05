<?php

namespace App\Actions\Guardianship;

use App\Actions\Action;
use App\Actions\Video\RemovePersonFromLiveCallsAction;
use App\Events\GuardianAccessChangedEvent;
use App\Jobs\HandleGuardianLossJob;
use App\Models\User;
use App\Support\BestEffort;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// SCRUM-288: a minor has just been left with no active guardian. Their therapies stay open and their
// sessions stay booked (Q2); every write is refused by the live check (EnsureMinorHasActiveGuardianAction).
//
// The decision that they have just become paused is the caller's, made once under the ward's row
// lock with locking reads -- RemoveGuardianFromWardAction (a guardian stops counting), or around()
// here (SCRUM-382: a date of birth change, which can make an adult a minor) -- so two changes at
// once announce it once. schedule() then queues the outbound work (SCRUM-382: a guardian with many
// wards would otherwise make a Daily call per ward inside the request) to run after the commit
// (HandleGuardianLossJob), and execute() does it, each step best effort and logged:
//  - takes them out of live calls (RemovePersonFromLiveCallsAction's GUARDIAN_LOSS policy: a 1:1
//    room ends for both, in a group only they leave), retried from the queue when it can't be done now;
//  - tells their open pages and the people involved (AnnounceGuardianAccessChangeAction), only when
//    they have an open therapy or group.
// Nothing happens if they have a guardian again (or are an adult) by the time it runs.
class HandleGuardianLossAction extends Action
{
    public const DOB_CHANGED = 'dob_changed';

    // guardians:announce-existing-pauses (accounts already paused before this ran on every path).
    public const EXISTING_PAUSE = 'existing_pause';

    // SCRUM-381: a safeguarding exception ran out (SafeguardingExceptionService), or a super
    // administrator ended it early (EndSafeguardingExceptionAction), with still no guardian.
    public const SAFEGUARDING_EXCEPTION_EXPIRED = 'safeguarding_exception_expired';

    public const SAFEGUARDING_EXCEPTION_ENDED = 'safeguarding_exception_ended';

    // Queues the work, after the caller's transaction commits (at once when there is none).
    public function schedule(int $wardId, string $trigger): void
    {
        HandleGuardianLossJob::dispatch($wardId, $trigger)->afterCommit();
    }

    /**
     * SCRUM-382: a change to $user that may make them paused without a guardian going (their date
     * of birth). In one transaction: locks their row, reads (locking reads) whether they are paused,
     * makes the change, re-reads, and schedules the loss handling only when it took them from not
     * paused to paused.
     *
     * @template T
     *
     * @param  callable(): T  $change
     * @return T
     */
    public function around(User $user, string $trigger, callable $change): mixed
    {
        return DB::transaction(function () use ($user, $trigger, $change) {
            $before = User::query()->whereKey($user->id)->lockForUpdate()->first();
            $wasPaused = $before?->isPausedForMissingGuardian(fresh: true);

            $result = $change();

            $after = User::query()->whereKey($user->id)->lockForUpdate()->first();

            if ($before && ! $wasPaused && $after?->isPausedForMissingGuardian(fresh: true)) {
                $this->schedule($after->id, $trigger);
            }

            return $result;
        });
    }

    public function execute(User $ward, string $trigger): void
    {
        $ward = User::query()->find($ward->id);

        if (! $ward || ! $ward->isPausedForMissingGuardian(fresh: true)) {
            return;
        }

        $open = GetWardOpenTherapiesAction::new()->execute($ward);
        $removedFrom = BestEffort::attempt(
            fn () => RemovePersonFromLiveCallsAction::new()->execute($ward, RemovePersonFromLiveCallsAction::GUARDIAN_LOSS),
            'guardian-loss-live-call-removal',
            ['ward' => $ward->id],
        ) ?? 0;

        if (GetWardOpenTherapiesAction::new()->hasAny($open)) {
            AnnounceGuardianAccessChangeAction::new()->execute($ward, $open, GuardianAccessChangedEvent::PAUSED);
        }

        Log::info('guardian_access.paused', [
            'ward' => $ward->id,
            'trigger' => $trigger,
            'therapies' => $open['therapies']->count(),
            'groups' => $open['groups']->count(),
            'video_rooms_left' => $removedFrom,
        ]);
    }
}
