<?php

namespace App\Actions\TwoFactor;

use App\Actions\Action;
use App\Exceptions\TwoFactorException;
use App\Models\User;
use App\Notifications\TwoFactorChangedNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
            $user->forceFill([
                'two_factor_secret' => null,
                'two_factor_recovery_codes' => null,
                'two_factor_confirmed_at' => null,
                'two_factor_last_used_step' => null,
                'remember_token' => Str::random(60),
            ])->save();

            $user->notify(new TwoFactorChangedNotification(TwoFactorChangedNotification::DISABLED));
        });
    }
}
