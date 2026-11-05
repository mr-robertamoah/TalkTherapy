<?php

namespace App\Actions\Guardianship;

use App\Actions\Action;
use App\Actions\VideoConsent\InvalidateVideoConsentAction;
use App\Enums\VideoConsentRevocationReasonEnum;
use App\Models\Guardianship;
use App\Models\User;
use App\Models\VideoConsent;
use Illuminate\Support\Facades\DB;

// SCRUM-288: the one path for a guardian no longer counting for a ward -- the guardianship removed,
// the guardian's account deleted (by themselves or an administrator), or an account-scope suspension
// of the guardian (the user's decision, Q3). Call it BEFORE the guardian stops counting, inside the
// caller's transaction when there is one: under a lock on the ward's row it reads (locking reads)
// whether the ward is paused now and whether they will be without this guardian, so two removals at
// once pause them exactly once. Removing a guardianship deletes its row here.
//
// Once committed (afterCommit): this guardian's still-valid video consents for the ward lapse when
// the guardianship or the account ends (not for a suspension, which ends), and a ward this leaves
// with no active guardian is paused (HandleGuardianLossAction::schedule(), queued). A deleted
// (soft-deleted) ward only has the row removed.
class RemoveGuardianFromWardAction extends Action
{
    public const GUARDIANSHIP_REMOVED = 'guardianship_removed';

    public const GUARDIAN_DELETED = 'guardian_deleted';

    public const GUARDIAN_SUSPENDED = 'guardian_suspended';

    public function execute(User $guardian, User $ward, string $trigger): void
    {
        DB::transaction(function () use ($guardian, $ward, $trigger) {
            $locked = User::query()->whereKey($ward->id)->lockForUpdate()->first();

            $wasPaused = $locked?->isPausedForMissingGuardian(fresh: true);
            $willBePaused = $locked?->isPausedForMissingGuardian(exceptGuardianIds: [$guardian->id]);

            if ($trigger === self::GUARDIANSHIP_REMOVED) {
                Guardianship::query()->where('guardian_id', $guardian->id)->where('ward_id', $ward->id)->delete();
            }

            if (! $locked) {
                return;
            }

            if ($trigger !== self::GUARDIAN_SUSPENDED) {
                // Best effort, so a failure can't skip the pause scheduled after it.
                DB::afterCommit(fn () => rescue(fn () => $this->lapseVideoConsents($guardian, $locked), report: true));
            }

            if (! $wasPaused && $willBePaused) {
                HandleGuardianLossAction::new()->schedule($locked->id, $trigger);
            }
        });
    }

    // Every ward of $guardian, in the one lock order (Guardianship::lockedWardsOf()).
    public function forAllWardsOf(User $guardian, string $trigger): void
    {
        DB::transaction(fn () => Guardianship::lockedWardsOf($guardian)
            ->each(fn (User $ward) => $this->execute($guardian, $ward, $trigger)));
    }

    // TT-3.1e-c/SCRUM-282 (moved here from DeleteGuardianshipAction): only this guardian's own
    // grants for this ward -- another guardian's stay valid. Through the shared invalidation path, so
    // a live call under a lapsed grant ends at once.
    private function lapseVideoConsents(User $guardian, User $ward): void
    {
        VideoConsent::query()
            ->where('ward_id', $ward->id)
            ->where('guardian_id', $guardian->id)
            ->whereNull('revoked_at')
            ->get()
            ->each(fn (VideoConsent $consent) => InvalidateVideoConsentAction::new()->execute(
                $consent,
                VideoConsentRevocationReasonEnum::guardianship_removed,
            ));
    }
}
