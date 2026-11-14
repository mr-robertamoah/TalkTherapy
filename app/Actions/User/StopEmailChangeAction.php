<?php

namespace App\Actions\User;

use App\Actions\Action;
use App\Actions\TwoFactor\RequestTwoFactorResetAction;
use App\Exceptions\TwoFactorException;
use App\Models\TwoFactorReset;
use App\Models\User;
use App\Notifications\TwoFactorResetRequestedNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

// SCRUM-427 (security review): "This wasn't me", from the warning to the account's current address -- the change is
// dropped, every session of the account ends ("remember me" too), and the password stops working: whoever made the
// change knows it, and would only start again. The owner sets a new one with "Forgot your password?" (it goes to the
// address this link came to). Only for the change the link was sent about, and only before it has taken effect.
//
// SCRUM-428: two-factor turned on while the change waited may be the intruder's phone -- or the owner's, if the old
// mailbox is the intruder (the app can't tell). It isn't removed at once: a two-factor reset is scheduled (the 48-hour
// SCRUM-424 path), and both addresses are told with a cancel link. Never for a change a super administrator asked for
// (no intruder session there).
class StopEmailChangeAction extends Action
{
    public function execute(int $userId, string $hash): bool
    {
        $stopped = DB::transaction(function () use ($userId, $hash) {
            $user = User::query()->whereKey($userId)->lockForUpdate()->first();

            if (! $user?->pending_email || ! hash_equals((string) $user->pendingEmailHash(), $hash)) {
                return false;
            }

            // The email was removed and this address is the one protected: put it back (security review: else
            // "Forgot your password?" couldn't reach them) -- unless another account has taken it since.
            $restore = ! $user->email && $user->last_verified_email
                && ! User::query()->whereKeyNot($user->id)->where('email', $user->last_verified_email)->exists();

            $twoFactorDuringWait = $user->pending_email_requested_at && $user->pending_email_requested_by === null
                && $user->hasTwoFactorEnabled() && $user->two_factor_confirmed_at?->gte($user->pending_email_requested_at);
            $droppedEmail = $user->pending_email;

            $user->forceFill([
                ...($restore ? ['email' => $user->last_verified_email, 'email_verified_at' => now()] : []),
                'pending_email' => null,
                'pending_email_requested_at' => null,
                'pending_email_requested_by' => null,
                'pending_email_confirmed_at' => null,
                'session_version' => (int) $user->session_version + 1,
                'remember_token' => Str::random(60),
                'password' => Str::random(64),
            ])->save();

            return ['two_factor_during_wait' => $twoFactorDuringWait, 'dropped' => $droppedEmail];
        });

        if (! $stopped) {
            return false;
        }

        Log::warning('Account email change stopped by its owner', ['user_id' => $userId, 'two_factor_during_wait' => $stopped['two_factor_during_wait']]);

        if ($stopped['two_factor_during_wait']) {
            $this->scheduleTwoFactorReset(User::query()->findOrFail($userId), $stopped['dropped']);
        }

        return true;
    }

    // The account's own address hears through RequestTwoFactorResetAction; the dropped new address is told too, with the
    // same cancel link -- whichever of the two is the owner can stop it.
    private function scheduleTwoFactorReset(User $user, string $droppedEmail): void
    {
        try {
            $reset = RequestTwoFactorResetAction::new()->execute($user, null, TwoFactorReset::SOURCE_EMAIL_STOP, 'Email change stopped ("This wasn\'t me") with two-factor turned on while it waited');
            // It applies only once the copy to the dropped address went out (security review), whatever the account's
            // own address is.
            $reset->forceFill(['mail_required' => true])->save();
        } catch (TwoFactorException) {
            // One is already scheduled (or two-factor is off again): the dropped address hears of that one.
            $reset = $user->pendingTwoFactorReset()->first();
        }

        if ($reset) {
            Notification::route('mail', $droppedEmail)->notify(TwoFactorResetRequestedNotification::for($reset));
        }
    }
}
