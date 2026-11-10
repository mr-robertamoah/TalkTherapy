<?php

namespace App\Actions\CounsellorVerification;

use App\Actions\Action;
use App\Actions\Admin\GetAdminToDoCountsAction;
use App\Enums\CounsellorVerificationEventEnum;
use App\Models\Counsellor;
use App\Notifications\CounsellorVerificationEndedNotification;
use App\Support\BestEffort;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

// SCRUM-31: daily (routes/console.php). A verification stops counting the moment it ends
// (Counsellor::verificationState() reads the date) -- this only records that it ended, once per end
// date (a `lapsed` event), and tells the counsellor. Looks back LOOKBACK_DAYS, so a run missed for a
// while still catches up without rescanning every counsellor who ever lapsed.
class RecordEndedCounsellorVerificationsAction extends Action
{
    public const LOOKBACK_DAYS = 30;

    public function execute(): int
    {
        $recorded = 0;

        Counsellor::query()
            ->whereNotNull('verified_at')
            ->whereNull('verification_revoked_at')
            ->whereBetween('verification_expires_at', [now()->subDays(self::LOOKBACK_DAYS), now()])
            ->select('id')
            ->chunkById(200, function ($counsellors) use (&$recorded) {
                foreach ($counsellors as $counsellor) {
                    try {
                        $recorded += $this->record($counsellor->id) ? 1 : 0;
                    } catch (Throwable $e) {
                        Log::error('Recording an ended counsellor verification failed.', ['counsellor' => $counsellor->id, 'exception' => $e->getMessage()]);
                    }
                }
            });

        // The admins' renewals count no longer includes them.
        if ($recorded) {
            GetAdminToDoCountsAction::forgetAfterCommit(['ended_verifications' => $recorded]);
        }

        return $recorded;
    }

    private function record(int $counsellorId): bool
    {
        return DB::transaction(function () use ($counsellorId) {
            $counsellor = Counsellor::query()->lockForUpdate()->find($counsellorId);

            if (! $counsellor || $counsellor->isVerified() || ! is_null($counsellor->verification_revoked_at) || is_null($counsellor->verification_expires_at)) {
                return false;
            }

            $expiresAt = $counsellor->verification_expires_at;
            $event = RecordCounsellorVerificationEventAction::new()->execute(
                $counsellor,
                CounsellorVerificationEventEnum::lapsed,
                expiresAt: $expiresAt,
                dedupeKey: "lapsed:{$counsellor->id}:{$expiresAt->timestamp}",
            );

            if (! $event) {
                return false;
            }

            BestEffort::attempt(fn () => $counsellor->notify(new CounsellorVerificationEndedNotification($expiresAt)), 'counsellor-verification-ended', ['counsellor' => $counsellor->id]);

            return true;
        });
    }
}
