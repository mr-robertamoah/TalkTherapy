<?php

namespace App\Actions\Guardianship;

use App\Actions\Action;
use App\Events\GuardianAccessChangedEvent;
use App\Events\TherapyAccessChangedEvent;
use App\Models\Therapy;
use App\Models\User;
use App\Notifications\GuardianAccessResumedNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

// SCRUM-288: a paused minor has an active guardian again -- a guardianship was created, or a
// guardian's account suspension was lifted or ran out (Q3). Access itself comes back through the
// live check; this only tells people, once. Turning 18 needs nothing: the live check stops applying.
//
// around() does the bookkeeping for a change that may bring a guardian back: inside one
// transaction it locks the ward(s) (in id order), reads with a locking read whether each is paused,
// makes the change, and once committed (afterCommit) re-reads and tells people -- so two guardians
// added at once announce it once. The expiry sweep, which can't read "before" any more, calls
// execute() with its own answer (SuspensionService).
class HandleGuardianRestoredAction extends Action
{
    public const GUARDIANSHIP_CREATED = 'guardianship_created';

    public const SUSPENSION_LIFTED = 'guardian_suspension_lifted';

    public const SUSPENSION_ENDED = 'guardian_suspension_ended';

    /**
     * @template T
     *
     * @param  User|Collection<int, User>  $wards  already-locked wards (Guardianship::lockedWardsOf()), or one ward to lock
     * @param  callable(): T  $change
     * @return T
     */
    public function around(User|Collection $wards, string $trigger, callable $change): mixed
    {
        return DB::transaction(function () use ($wards, $trigger, $change) {
            $wards = $wards instanceof User
                ? User::query()->whereKey($wards->id)->lockForUpdate()->get()
                : $wards;
            $wasPaused = $wards->mapWithKeys(fn (User $ward) => [$ward->id => $ward->isPausedForMissingGuardian(fresh: true)]);

            $result = $change();

            $wards->filter(fn (User $ward) => $wasPaused[$ward->id])
                ->each(fn (User $ward) => DB::afterCommit(fn () => $this->execute($ward, true, $trigger)));

            return $result;
        });
    }

    public function execute(User $ward, bool $wasPaused, string $trigger): void
    {
        $ward = User::query()->find($ward->id);

        if (! $wasPaused || ! $ward || $ward->isPausedForMissingGuardian(fresh: true)) {
            return;
        }

        $open = GetWardOpenTherapiesAction::new()->execute($ward);

        if (! GetWardOpenTherapiesAction::new()->hasAny($open)) {
            return;
        }

        $this->attempt(fn () => GuardianAccessChangedEvent::dispatch($ward->id, GuardianAccessChangedEvent::RESUMED), $ward);
        $open['therapies']->each(fn (Therapy $therapy) => $this->attempt(fn () => TherapyAccessChangedEvent::dispatch($therapy->id), $ward));

        $this->attempt(fn () => $ward->notify(new GuardianAccessResumedNotification), $ward);
        $open['therapies']
            ->filter(fn (Therapy $therapy) => $therapy->counsellor?->user)
            ->each(fn (Therapy $therapy) => $this->attempt(
                fn () => Notification::send($therapy->counsellor->user, new GuardianAccessResumedNotification($therapy, $therapy->displayNameOf($ward))),
                $ward,
            ));

        Log::info('guardian_access.resumed', [
            'ward' => $ward->id,
            'trigger' => $trigger,
            'therapies' => $open['therapies']->count(),
            'groups' => $open['groups']->count(),
        ]);
    }

    private function attempt(callable $step, User $ward): void
    {
        try {
            $step();
        } catch (Throwable $th) {
            Log::warning('Could not complete a step of resuming a minor\'s therapies.', ['ward' => $ward->id, 'error' => $th->getMessage()]);
        }
    }
}
