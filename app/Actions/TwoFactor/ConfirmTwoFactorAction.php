<?php

namespace App\Actions\TwoFactor;

use App\Actions\Action;
use App\Exceptions\TwoFactorException;
use App\Models\User;
use App\Notifications\TwoFactorChangedNotification;
use App\Support\TwoFactorAttempts;
use App\Support\TwoFactorCodes;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// SCRUM-34: turns two-factor sign-in on once a code from the newly scanned app proves it works -- only then is the
// secret saved to the account. Returns the recovery codes: shown once, only their keyed hashes kept. A new remember-me
// token stops other browsers' "remember me", and other open sessions end (EndStaleSessions, SCRUM-34b).
class ConfirmTwoFactorAction extends Action
{
    /**
     * @return list<string> the recovery codes
     */
    public function execute(User $user, Session $session, #[\SensitiveParameter] string $code): array
    {
        $secret = StartTwoFactorSetupAction::pendingSecret($user, $session);

        if (! $secret) {
            throw new TwoFactorException('Your setup has expired. Please start again.', 422);
        }

        // The step the code was for (0 = no earlier one), so this code can't then be used again.
        $step = TwoFactorAttempts::attempt($user, function () use ($secret, $code) {
            $step = TwoFactorCodes::google2fa()->verifyKeyNewer($secret, TwoFactorCodes::normalise($code), 0);

            return is_int($step) ? $step : null;
        });

        if ($step === null) {

            throw new TwoFactorException('That code didn\'t work. Check the time on your phone and try the newest code.', 422);
        }

        [$codes, $hashes] = TwoFactorCodes::newRecoveryCodes();

        DB::transaction(function () use ($user, $secret, $hashes, $step) {
            // Two confirms at once: only the first turns it on.
            $locked = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

            if ($locked->hasTwoFactorEnabled()) {
                throw new TwoFactorException('Two-factor sign-in is already on.', 422);
            }

            $locked->forceFill([
                'two_factor_secret' => $secret,
                'two_factor_recovery_codes' => $hashes,
                'two_factor_confirmed_at' => now(),
                'two_factor_last_used_step' => $step,
                'remember_token' => Str::random(60),
            ])->save();

            $locked->notify(new TwoFactorChangedNotification(TwoFactorChangedNotification::ENABLED));
        });

        $session->forget(StartTwoFactorSetupAction::SESSION_KEY);
        $user->refresh();

        return $codes;
    }
}
