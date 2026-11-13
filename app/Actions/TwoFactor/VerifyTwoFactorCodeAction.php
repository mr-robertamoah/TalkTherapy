<?php

namespace App\Actions\TwoFactor;

use App\Actions\Action;
use App\Models\User;
use App\Support\TwoFactorAttempts;
use App\Support\TwoFactorCodes;
use Illuminate\Support\Facades\DB;

// SCRUM-34: whether $code is good for $user (who has two-factor sign-in on) -- a code from their authenticator app
// (each one usable once: the time-step it was for is recorded, atomically, so two requests can't both use it), or one
// of their recovery codes (used up, under a lock). 'totp', 'recovery', or null. Shared by sign-in (SCRUM-34b), new
// recovery codes and turning it off. Wrong codes count towards the account's limit (TwoFactorAttempts) -- refused,
// whatever the code, while it's reached.
class VerifyTwoFactorCodeAction extends Action
{
    public const TOTP = 'totp';

    public const RECOVERY = 'recovery';

    public function execute(User $user, #[\SensitiveParameter] string $code): ?string
    {
        if (! $user->hasTwoFactorEnabled()) {
            return null;
        }

        $code = TwoFactorCodes::normalise($code);

        return TwoFactorAttempts::attempt($user, fn () => match (true) {
            (bool) preg_match('/^\d{6}$/', $code) => $this->totp($user, $code) ? self::TOTP : null,
            $code !== '' => $this->recovery($user, $code) ? self::RECOVERY : null,
            default => null,
        });
    }

    private function totp(User $user, string $code): bool
    {
        // With a previous step (0 when there is none yet) the library returns the step the code was for, not true.
        $step = TwoFactorCodes::google2fa()->verifyKeyNewer($user->two_factor_secret, $code, $user->two_factor_last_used_step ?? 0);

        if (! is_int($step)) {
            return false;
        }

        // Only the first request to claim this step wins.
        return (bool) User::query()->whereKey($user->id)
            ->where(fn ($query) => $query->whereNull('two_factor_last_used_step')->orWhere('two_factor_last_used_step', '<', $step))
            ->update(['two_factor_last_used_step' => $step]);
    }

    private function recovery(User $user, string $code): bool
    {
        $hashes = TwoFactorCodes::candidateHashes($code);

        return DB::transaction(function () use ($user, $hashes) {
            $locked = User::query()->whereKey($user->id)->lockForUpdate()->first();
            $codes = $locked?->two_factor_recovery_codes ?? [];
            $match = collect($codes)->search(fn (string $stored) => collect($hashes)->contains(fn (string $hash) => hash_equals($stored, $hash)));

            if ($match === false) {
                return false;
            }

            unset($codes[$match]);
            $locked->forceFill(['two_factor_recovery_codes' => array_values($codes)])->save();

            return true;
        });
    }
}
