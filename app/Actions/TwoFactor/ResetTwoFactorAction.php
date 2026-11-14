<?php

namespace App\Actions\TwoFactor;

use App\Actions\Action;
use App\Exceptions\TwoFactorException;
use App\Models\User;
use App\Notifications\TwoFactorChangedNotification;
use App\Support\TwoFactorAttempts;
use Illuminate\Support\Facades\DB;

// SCRUM-34c/d: the team turns off someone's two-factor sign-in (a lost phone and codes) -- from the admin users list
// (ResetTwoFactorByAdminAction, which checks who may) or on the server (`user:two-factor-reset`). The same as the person
// turning it off (other sessions end, "remember me" stops), plus a "reset by the team" notice and the wrong-code count
// cleared. Who did it is logged by the caller.
class ResetTwoFactorAction extends Action
{
    public function execute(User $user): void
    {
        DB::transaction(function () use ($user) {
            // Checked again under the row lock: two resets at once, or one as the person turns it on again.
            $locked = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

            if (! $locked->hasTwoFactorEnabled()) {
                throw new TwoFactorException('This account doesn\'t have two-factor sign-in on.', 422);
            }

            $locked->turnOffTwoFactor();

            $locked->notify(new TwoFactorChangedNotification(TwoFactorChangedNotification::RESET_BY_ADMIN));
        });

        TwoFactorAttempts::forget($user);
    }
}
