<?php

namespace App\Actions\Guardianship;

use App\Actions\Action;
use App\Actions\Therapy\IsTherapyBlockedForAction;
use App\Events\GuardianAccessChangedEvent;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// SCRUM-288: a paused minor has an active guardian again -- a guardianship was created, or a
// guardian's account suspension was lifted or ran out (Q3). Access itself comes back through the
// live check; this only tells people, once (AnnounceGuardianAccessChangeAction). Turning 18 needs
// nothing: the live check stops applying.
//
// around() does the bookkeeping for a change that may bring a guardian back: inside one
// transaction it locks the ward(s) (in id order), reads with a locking read whether each is paused,
// makes the change, and once committed (afterCommit) re-reads and tells people -- so two guardians
// added at once announce it once. The expiry sweep, which can't read "before" any more, calls
// execute() with its own answer (SuspensionService).
//
// SCRUM-381: a super administrator's safeguarding exception also lifts the pause
// (GrantSafeguardingExceptionAction, through around()); $continuesUntil is its end, so the notices
// say "can continue for now, until ..." rather than "has a guardian again".
class HandleGuardianRestoredAction extends Action
{
    public const GUARDIANSHIP_CREATED = 'guardianship_created';

    public const SUSPENSION_LIFTED = 'guardian_suspension_lifted';

    public const SUSPENSION_ENDED = 'guardian_suspension_ended';

    public const SAFEGUARDING_EXCEPTION_GRANTED = 'safeguarding_exception_granted';

    /**
     * @template T
     *
     * @param  User|Collection<int, User>  $wards  already-locked wards (Guardianship::lockedWardsOf()), or one ward to lock
     * @param  callable(): T  $change
     * @return T
     */
    public function around(User|Collection $wards, string $trigger, callable $change, ?CarbonInterface $continuesUntil = null): mixed
    {
        return DB::transaction(function () use ($wards, $trigger, $change, $continuesUntil) {
            $wards = $wards instanceof User
                ? User::query()->whereKey($wards->id)->lockForUpdate()->get()
                : $wards;
            $wasPaused = $wards->mapWithKeys(fn (User $ward) => [$ward->id => $ward->isPausedForMissingGuardian(fresh: true)]);

            $result = $change();

            $wards->filter(fn (User $ward) => $wasPaused[$ward->id])
                ->each(fn (User $ward) => DB::afterCommit(fn () => $this->execute($ward, true, $trigger, $continuesUntil)));

            return $result;
        });
    }

    public function execute(User $ward, bool $wasPaused, string $trigger, ?CarbonInterface $continuesUntil = null): void
    {
        $ward = User::query()->find($ward->id);

        if (! $wasPaused || ! $ward || $ward->isPausedForMissingGuardian(fresh: true)) {
            return;
        }

        $open = $this->carryingOn($ward);

        if (! GetWardOpenTherapiesAction::new()->hasAny($open)) {
            Log::info('guardian_access.resume_not_announced', ['ward' => $ward->id, 'trigger' => $trigger]);

            return;
        }

        AnnounceGuardianAccessChangeAction::new()->execute($ward, $open, GuardianAccessChangedEvent::RESUMED, $continuesUntil);

        Log::info('guardian_access.resumed', [
            'ward' => $ward->id,
            'trigger' => $trigger,
            'therapies' => $open['therapies']->count(),
            'groups' => $open['groups']->count(),
        ]);
    }

    // SCRUM-382: only what can really carry on now -- a therapy still blocked for another reason
    // (their own account suspension, its counsellor's suspension) is left out, and with nothing
    // left nobody is told (IsTherapyBlockedForAction, the one "anything else still blocking?" check).
    // SCRUM-381: also who a renewed safeguarding exception is announced to.
    /** @return array{therapies: Collection, groups: Collection} */
    public function carryingOn(User $ward): array
    {
        $blocked = IsTherapyBlockedForAction::new();

        return collect(GetWardOpenTherapiesAction::new()->execute($ward))
            ->map(fn ($therapies) => $therapies->reject(fn ($therapy) => $blocked->execute($ward, $therapy))->values())
            ->all();
    }
}
