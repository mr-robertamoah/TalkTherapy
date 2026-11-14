<?php

namespace App\Actions\TwoFactor;

use App\Actions\Action;
use App\Exceptions\TwoFactorException;
use App\Models\TwoFactorReset;
use App\Models\User;
use App\Notifications\TwoFactorChangedNotification;
use Illuminate\Support\Facades\DB;

// SCRUM-34: turns two-factor sign-in off (the password was checked by the request) -- also needs a current code or a
// recovery code, so a stolen password alone can't remove it. Other sessions end (EndStaleSessions) and "remember me"
// elsewhere stops working (SCRUM-34b).
class DisableTwoFactorAction extends Action
{
    public function execute(User $user, #[\SensitiveParameter] string $code): void
    {
        if (! $user->hasTwoFactorEnabled()) {
            throw new TwoFactorException('Two-factor sign-in is not on.', 422);
        }

        if (VerifyTwoFactorCodeAction::new()->execute($user, $code) === null) {
            throw new TwoFactorException('That code didn\'t work. Use a code from your app or one of your recovery codes.', 422);
        }

        DB::transaction(function () use ($user) {
            $user->turnOffTwoFactor();

            $user->notify(new TwoFactorChangedNotification(TwoFactorChangedNotification::DISABLED));
        });

        // SCRUM-424: turned off by them -- a reset the team scheduled has nothing left to do.
        CancelTwoFactorResetAction::new()->forUser($user, TwoFactorReset::CANCELLED_BY_SELF_CHANGE, $user);
    }
}
