<?php

namespace App\Actions\SafeguardingException;

use App\Actions\Action;
use App\Actions\Guardianship\HandleGuardianLossAction;
use App\Exceptions\SafeguardingExceptionRefusedException;
use App\Models\SafeguardingException;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// SCRUM-381: a super administrator ends a safeguarding exception early, with a reason. The row is
// kept (revoked). When that leaves the minor paused again (still no guardian), the usual loss
// handling runs once committed (HandleGuardianLossAction::schedule): out of live calls, and the
// minor and their counsellors told the therapy is paused. Locks the minor's row, then the exception
// (SafeguardingException::lockWithWard()).
class EndSafeguardingExceptionAction extends Action
{
    public const NOT_ACTIVE = 'This safeguarding exception has already ended.';

    public function execute(?User $admin, ?SafeguardingException $exception, ?string $reason): SafeguardingException
    {
        if (! $exception) {
            throw new SafeguardingExceptionRefusedException('Safeguarding exception not found.', 404);
        }

        EnsureCanManageSafeguardingExceptionAction::new()->execute($admin, User::withTrashed()->find($exception->ward_id));

        $reason = trim((string) $reason);
        if ($reason === '') {
            throw new SafeguardingExceptionRefusedException('Give a reason for ending the exception.', 422);
        }
        if (mb_strlen($reason) > EnsureSafeguardingExceptionTermsAction::MAX_REASON) {
            throw new SafeguardingExceptionRefusedException('The reason must not be longer than '.EnsureSafeguardingExceptionTermsAction::MAX_REASON.' characters.', 422);
        }

        $ended = DB::transaction(function () use ($admin, $exception, $reason) {
            [$locked, $ward] = SafeguardingException::lockWithWard($exception->id);

            if (! $locked) {
                throw new SafeguardingExceptionRefusedException('Safeguarding exception not found.', 404);
            }

            if (! $locked->isActive()) {
                throw new SafeguardingExceptionRefusedException(self::NOT_ACTIVE, 422);
            }

            $wasPaused = (bool) $ward?->isPausedForMissingGuardian(fresh: true);

            $locked->forceFill([
                'ended_at' => now(),
                'ended_by' => $admin->id,
                'end_kind' => SafeguardingException::END_REVOKED,
                'end_reason' => $reason,
                'end_processed_at' => now(),
            ])->save();

            if ($ward && ! $wasPaused && $ward->isPausedForMissingGuardian(fresh: true)) {
                HandleGuardianLossAction::new()->schedule($ward->id, HandleGuardianLossAction::SAFEGUARDING_EXCEPTION_ENDED);
            }

            Log::info('safeguarding_exception.ended', ['exception' => $locked->id, 'ward' => $locked->ward_id, 'by' => $admin->id]);

            return $locked;
        });

        // An instance of the minor the caller loaded memoises that it was in force.
        if ($exception->relationLoaded('ward')) {
            $exception->ward?->forgetSafeguardingException();
        }

        return $ended;
    }
}
