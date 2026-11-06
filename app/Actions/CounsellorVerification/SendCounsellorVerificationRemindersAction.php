<?php

namespace App\Actions\CounsellorVerification;

use App\Actions\Action;
use App\Enums\CounsellorVerificationEventEnum;
use App\Enums\RequestStatusEnum;
use App\Enums\RequestTypeEnum;
use App\Models\Counsellor;
use App\Models\Request;
use App\Models\User;
use App\Notifications\CounsellorVerificationEndingAdminNotification;
use App\Notifications\CounsellorVerificationEndingNotification;
use App\Support\BestEffort;
use App\Support\CounsellorVerification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

// SCRUM-31: daily (routes/console.php). Each currently verified counsellor whose verification ends
// within a reminder window (30 and 7 days, config) and who hasn't already asked to re-verify gets the
// reminder for the narrowest window they're in -- once: a `reminder_sent` event keyed by counsellor,
// window and end date (so renewing starts the reminders afresh, and a missed run never sends two).
// The administrator who last accepted their verification hears with it; every administrator sees
// them in the renewals count. One counsellor failing is logged and skipped.
class SendCounsellorVerificationRemindersAction extends Action
{
    public function execute(): int
    {
        $sent = 0;

        Counsellor::query()
            ->whereRenewalDue()
            ->select('id')
            ->chunkById(200, function ($counsellors) use (&$sent) {
                foreach ($counsellors as $counsellor) {
                    try {
                        $sent += $this->remind($counsellor->id) ? 1 : 0;
                    } catch (Throwable $e) {
                        Log::error('A counsellor verification reminder failed.', ['counsellor' => $counsellor->id, 'exception' => $e->getMessage()]);
                    }
                }
            });

        return $sent;
    }

    private function remind(int $counsellorId): bool
    {
        return DB::transaction(function () use ($counsellorId) {
            $counsellor = Counsellor::query()->lockForUpdate()->find($counsellorId);

            if (! $counsellor?->isVerified() || $counsellor->hasPendingCounsellorVerificationRequest()) {
                return false;
            }

            $daysLeft = (int) max(0, ceil(now()->floatDiffInDays($counsellor->verification_expires_at, false)));
            $window = collect(CounsellorVerification::reminderDays())->filter(fn (int $days) => $daysLeft <= $days)->min();

            if (is_null($window)) {
                return false;
            }

            $event = RecordCounsellorVerificationEventAction::new()->execute(
                $counsellor,
                CounsellorVerificationEventEnum::reminderSent,
                expiresAt: $counsellor->verification_expires_at,
                windowDays: $window,
                dedupeKey: "reminder:{$counsellor->id}:{$window}:{$counsellor->verification_expires_at->timestamp}",
            );

            if (! $event) {
                return false;
            }

            $expiresAt = $counsellor->verification_expires_at;
            BestEffort::attempt(fn () => $counsellor->notify(new CounsellorVerificationEndingNotification($expiresAt, $daysLeft)), 'counsellor-verification-reminder', ['counsellor' => $counsellor->id]);

            $approver = $this->lastApprover($counsellor);
            if ($approver) {
                BestEffort::attempt(fn () => $approver->notify(new CounsellorVerificationEndingAdminNotification($counsellor, $expiresAt, $daysLeft)), 'counsellor-verification-reminder-admin', ['counsellor' => $counsellor->id]);
            }

            return true;
        });
    }

    // Who accepted their latest verification, while still an administrator and not the counsellor
    // themselves. Null for one accepted before that was recorded (SCRUM-387).
    private function lastApprover(Counsellor $counsellor): ?User
    {
        $approver = Request::query()
            ->whereFrom($counsellor)
            ->where('type', RequestTypeEnum::counsellor->value)
            ->where('status', RequestStatusEnum::accepted->value)
            ->whereNotNull('responded_by_id')
            ->latest('responded_at')
            ->first()?->respondedBy;

        return $approver && $approver->isAdmin() && $approver->id !== (int) $counsellor->user_id ? $approver : null;
    }
}
