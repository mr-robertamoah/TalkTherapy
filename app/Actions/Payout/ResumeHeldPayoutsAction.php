<?php

namespace App\Actions\Payout;

use App\Actions\Action;
use App\Enums\CounsellorPayoutStatusEnum;
use App\Jobs\ProcessCounsellorPayoutJob;
use App\Models\CounsellorPayout;
use App\Models\User;

// SCRUM-363: a payout requested before a suspension landed waits, still pending, while it lasts
// (ProcessCounsellorPayoutJob checks again before calling Paystack). When the suspension ends --
// expiry, lift, or a successful appeal -- this sends it again. Nothing earned is lost.
class ResumeHeldPayoutsAction extends Action
{
    public function execute(?User $user): void
    {
        $counsellor = $user?->forgetActiveSuspension()->counsellor;

        if (! $counsellor || $counsellor->isSuspended()) {
            return;
        }

        CounsellorPayout::query()
            ->where('counsellor_id', $counsellor->id)
            ->where('status', CounsellorPayoutStatusEnum::pending->value)
            ->pluck('id')
            ->each(fn (int $id) => ProcessCounsellorPayoutJob::dispatch($id));
    }
}
