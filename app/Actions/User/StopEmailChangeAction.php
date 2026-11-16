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
// SCRUM-428/431: two-factor turned on while the change waited -- or in the days before it was asked for (an intruder
// can turn it on first) -- may be the intruder's phone, or the owner's if the old mailbox is the intruder (the app
// can't tell). It isn't removed at once: a two-factor reset is scheduled (the 48-hour
// SCRUM-424 path), and both addresses are told with a cancel link. Never for a change a super administrator asked for
// (no intruder session there).
//
// SCRUM-455: Google sign-in is disconnected as well (it could be the intruder's way back in).
//
// SCRUM-433: an account with no email gets its remembered address back only when it was remembered recently
// (auth.email_stop_restore_days): a long-remembered mailbox may have passed to someone else, who could then reset the
// password. Otherwise the account is left with no email and its owner goes through support.
class StopEmailChangeAction extends Action
{
    public const STOPPED = 'stopped';

    // Stopped, and the account has no email to reset the password through.
    public const STOPPED_WITHOUT_EMAIL = 'stopped-without-email';

    // STOPPED / STOPPED_WITHOUT_EMAIL, or null when the link is no longer valid.
    public function execute(int $userId, string $hash): ?string
    {
        $stopped = DB::transaction(function () use ($userId, $hash) {
            $user = User::query()->whereKey($userId)->lockForUpdate()->first();

            if (! $user?->pending_email || ! hash_equals((string) $user->pendingEmailHash(), $hash)) {
                return false;
            }

            // The email was removed and this address is the one protected: put it back (security review: else
            // "Forgot your password?" couldn't reach them) -- unless another account has taken it since, or it was
            // remembered too long ago (SCRUM-433; no date means before that was recorded: too long ago).
            $restore = ! $user->email && $user->last_verified_email
                && $user->lastVerifiedEmailIsRecent()
                && ! User::query()->whereKeyNot($user->id)->where('email', $user->last_verified_email)->exists();

            // SCRUM-431: or in the days before it was asked for -- an intruder can turn it on first.
            $twoFactorDuringWait = $user->pending_email_requested_at && $user->pending_email_requested_by === null
                && $user->hasTwoFactorEnabled() && $user->two_factor_confirmed_at?->gte(
                    $user->pending_email_requested_at->copy()->subDays((int) config('auth.email_stop_two_factor_lookback_days')));
            $droppedEmail = $user->pending_email;

            // SCRUM-455 (security review): a Google account connected meanwhile may be the intruder's -- let go of it
            // too. The owner connects theirs again once back in (the stop page says so).
            $user->forgetGoogle()->forceFill([
                ...($restore ? ['email' => $user->last_verified_email, 'email_verified_at' => now()] : []),
                'pending_email' => null,
                'pending_email_requested_at' => null,
                'pending_email_requested_by' => null,
                'pending_email_confirmed_at' => null,
                'session_version' => (int) $user->session_version + 1,
                'remember_token' => Str::random(60),
                'password' => Str::random(64),
            ])->save();

            return ['two_factor_during_wait' => $twoFactorDuringWait, 'dropped' => $droppedEmail, 'has_email' => (bool) $user->email, 'restored' => $restore];
        });

        if (! $stopped) {
            return null;
        }

        Log::warning('Account email change stopped by its owner', [
            'user_id' => $userId,
            'two_factor_during_wait' => $stopped['two_factor_during_wait'],
            'email_restored' => $stopped['restored'],
            'has_email' => $stopped['has_email'],
        ]);

        if ($stopped['two_factor_during_wait']) {
            $this->scheduleTwoFactorReset(User::query()->findOrFail($userId), $stopped['dropped']);
        }

        return $stopped['has_email'] ? self::STOPPED : self::STOPPED_WITHOUT_EMAIL;
    }

    // The account's own address hears through RequestTwoFactorResetAction; the dropped new address is told too, with the
    // same cancel link -- whichever of the two is the owner can stop it.
    private function scheduleTwoFactorReset(User $user, string $droppedEmail): void
    {
        try {
            $reset = RequestTwoFactorResetAction::new()->execute($user, null, TwoFactorReset::SOURCE_EMAIL_STOP, 'Email change stopped ("This wasn\'t me") with two-factor turned on shortly before or while it waited');
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
