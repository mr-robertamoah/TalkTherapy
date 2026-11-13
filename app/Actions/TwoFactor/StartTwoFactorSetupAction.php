<?php

namespace App\Actions\TwoFactor;

use App\Actions\Action;
use App\Exceptions\TwoFactorException;
use App\Models\User;
use App\Support\TwoFactorCodes;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Crypt;

// SCRUM-34: a new secret to scan. Kept (encrypted) in this browser's session only, for 15 minutes, until a code from the
// app confirms it (ConfirmTwoFactorAction) -- so a setup left half done changes nothing, and another session can't swap
// in its own secret for this one (security review). Once on, it has to be turned off before setting up again.
class StartTwoFactorSetupAction extends Action
{
    public const SESSION_KEY = 'two_factor.pending_setup';

    public const MINUTES = 15;

    /**
     * @return array{secret: string, qrSvg: string}
     */
    public function execute(User $user, Session $session): array
    {
        if ($user->hasTwoFactorEnabled()) {
            throw new TwoFactorException('Two-factor sign-in is already on.', 422);
        }

        $secret = TwoFactorCodes::newSecret();

        $session->put(self::SESSION_KEY, [
            'user_id' => $user->id,
            'secret' => Crypt::encryptString($secret),
            'expires_at' => now()->addMinutes(self::MINUTES)->getTimestamp(),
        ]);

        return ['secret' => $secret, 'qrSvg' => TwoFactorCodes::qrSvg($secret, $user->username ?? (string) $user->id)];
    }

    // The secret this session is setting up for $user, or null (none, someone else's, or expired).
    public static function pendingSecret(User $user, Session $session): ?string
    {
        $pending = $session->get(self::SESSION_KEY);

        if (! is_array($pending) || ($pending['user_id'] ?? null) !== $user->id || ($pending['expires_at'] ?? 0) < now()->getTimestamp()) {
            return null;
        }

        return rescue(fn () => Crypt::decryptString($pending['secret']), null, report: false);
    }
}
