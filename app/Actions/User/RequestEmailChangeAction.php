<?php

namespace App\Actions\User;

use App\Actions\Action;
use App\Exceptions\UserException;
use App\Models\User;
use App\Notifications\AccountEmailChangedByTeamNotification;
use App\Notifications\ConfirmNewEmailNotification;
use App\Notifications\EmailChangedNotification;
use App\Support\MaskedEmail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;

// SCRUM-427: a new email for an account -- by the person (their profile, with their password) or a super administrator
// (the admin user edit). It waits as pending until its owner confirms it (ConfirmPendingEmailAction) -- and then, when
// the current address is verified, a while longer (ApplyDueEmailChangesAction). Until it takes effect the current
// address stays the account's, password reset included. The current address, when verified, is told now (masked) with
// a "This wasn't me" link; a change the team asked for is told in the app too. Asking again replaces an earlier request.
class RequestEmailChangeAction extends Action
{
    // Security review: new addresses an account may be sent links to in a day -- not a free mail relay.
    public const PER_DAY = 5;

    // $name: the one the account had before the save that asked for this (a name changed in the same save mustn't be
    // the one the warning greets).
    public function execute(User $user, string $newEmail, ?User $by = null, ?string $name = null): void
    {
        $byTeam = $by && ! $by->is($user);
        $name ??= $user->name ?: $user->username;

        // The address already waiting, asked for again: just its link again (code review: not a second warning to the
        // current address, nor a way round the resend limit).
        if ($user->pending_email && mb_strtolower($user->pending_email) === mb_strtolower($newEmail)) {
            $this->resend($user);

            return;
        }

        $key = 'email-change:'.$user->id;
        if (RateLimiter::tooManyAttempts($key, self::PER_DAY)) {
            throw new UserException('Too many email changes asked for today. Please try again tomorrow.', 429);
        }
        RateLimiter::hit($key, 86400);

        $user->forceFill([
            'pending_email' => $newEmail,
            'pending_email_requested_at' => now(),
            'pending_email_requested_by' => $byTeam ? $by->id : null,
            'pending_email_confirmed_at' => null,
        ])->save();

        Notification::route('mail', $newEmail)->notify(new ConfirmNewEmailNotification($user->id, $newEmail, $user->pendingEmailHash()));

        // The current verified address -- or one verified until recently (removed or unticked: security review).
        EmailChangedNotification::sendTo($user->protectedEmail(), $name, $newEmail, byTeam: $byTeam, userId: $user->id,
            pendingHash: $user->pendingEmailHash(), accountHasEmail: (bool) $user->email);

        if ($byTeam) {
            $user->notify(new AccountEmailChangedByTeamNotification(MaskedEmail::of($newEmail)));
        }

        Log::notice($byTeam ? 'Account email change requested by an administrator' : 'Account email change requested', array_filter([
            'user_id' => $user->id, 'admin_id' => $byTeam ? $by->id : null,
        ]));
    }

    public function cancel(User $user): bool
    {
        if (! $user->pending_email) {
            return false;
        }

        $byTeam = $user->pending_email_requested_by !== null;
        $user->clearPendingEmail();

        // Code review: the record shows a request the team made being dropped by the person.
        Log::notice('Account email change cancelled', ['user_id' => $user->id, 'requested_by_team' => $byTeam]);

        return true;
    }

    // The link again, for the same pending address (a new 24 hours) -- not once it's confirmed, and a few times a day at
    // most (security review: else 3 a minute to an address of the requester's choosing).
    public function resend(User $user): bool
    {
        if (! $user->pending_email || $user->pending_email_confirmed_at) {
            return false;
        }

        $key = 'email-change-resend:'.$user->id;
        if (RateLimiter::tooManyAttempts($key, self::PER_DAY)) {
            throw new UserException('The link has been sent several times today. Please try again tomorrow.', 429);
        }
        RateLimiter::hit($key, 86400);

        Notification::route('mail', $user->pending_email)->notify(new ConfirmNewEmailNotification($user->id, $user->pending_email, $user->pendingEmailHash()));

        return true;
    }
}
