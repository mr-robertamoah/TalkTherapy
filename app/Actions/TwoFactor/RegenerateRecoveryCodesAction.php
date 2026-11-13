<?php

namespace App\Actions\TwoFactor;

use App\Actions\Action;
use App\Exceptions\TwoFactorException;
use App\Models\User;
use App\Notifications\TwoFactorChangedNotification;
use App\Support\TwoFactorCodes;
use Illuminate\Support\Facades\DB;

// SCRUM-34: a fresh set of recovery codes; the old ones stop working. Needs the password (checked by the request) and a
// current code -- the same proof as turning it off, or a password alone could make codes and then turn it off with
// one (security review).
class RegenerateRecoveryCodesAction extends Action
{
    /**
     * @return list<string>
     */
    public function execute(User $user, #[\SensitiveParameter] string $code): array
    {
        if (! $user->hasTwoFactorEnabled()) {
            throw new TwoFactorException('Two-factor sign-in is not on.', 422);
        }

        if (VerifyTwoFactorCodeAction::new()->execute($user, $code) === null) {
            throw new TwoFactorException('That code didn\'t work. Use a code from your app or one of your recovery codes.', 422);
        }

        [$codes, $hashes] = TwoFactorCodes::newRecoveryCodes();

        DB::transaction(function () use ($user, $hashes) {
            $user->forceFill(['two_factor_recovery_codes' => $hashes])->save();
            $user->notify(new TwoFactorChangedNotification(TwoFactorChangedNotification::RECOVERY_CODES_REGENERATED));
        });

        return $codes;
    }
}
