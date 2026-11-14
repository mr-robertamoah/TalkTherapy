<?php

namespace App\Actions\TwoFactor;

use App\Actions\Action;
use App\Models\TwoFactorReset;
use App\Models\User;
use App\Notifications\TwoFactorChangedNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// SCRUM-424: stops a scheduled reset before it applies -- the person (the emailed link, signing in with a code, their
// profile, changing their own two-factor), a super administrator, or the server command. Idempotent: one that already
// applied or was cancelled is left as it is (false). The person hears of it, except when it simply no longer applies
// (two-factor set up again or turned off meanwhile).
class CancelTwoFactorResetAction extends Action
{
    private const QUIET = [TwoFactorReset::SUPERSEDED, TwoFactorReset::ALREADY_OFF, TwoFactorReset::CANCELLED_BY_SELF_CHANGE];

    public function execute(TwoFactorReset $reset, string $reason, ?User $by = null): bool
    {
        $cancelled = DB::transaction(function () use ($reset, $reason) {
            $user = User::query()->whereKey($reset->user_id)->lockForUpdate()->first();
            $locked = TwoFactorReset::query()->whereKey($reset->id)->lockForUpdate()->first();

            if (! $user || ! $locked?->isPending()) {
                return false;
            }

            $locked->forceFill(['cancelled_at' => now(), 'cancel_reason' => $reason])->save();

            if (! in_array($reason, self::QUIET, true)) {
                $user->notify(new TwoFactorChangedNotification(TwoFactorChangedNotification::RESET_CANCELLED));
            }

            return true;
        });

        if ($cancelled) {
            Log::notice('Two-factor sign-in reset cancelled', ['reset_id' => $reset->id, 'user_id' => $reset->user_id, 'reason' => $reason, 'by' => $by?->id]);
        }

        return $cancelled;
    }

    // Whatever reset is waiting for this account, if any.
    public function forUser(User $user, string $reason, ?User $by = null): bool
    {
        $pending = $user->pendingTwoFactorReset()->first();

        return $pending ? $this->execute($pending, $reason, $by) : false;
    }
}
