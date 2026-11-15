<?php

namespace App\Actions\User;

use App\Actions\Action;
use App\Models\User;
use App\Notifications\EmailChangedNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

// SCRUM-427: a confirmed new address becomes the account's email, verified (at once when there was no verified address
// to protect, else once the wait is over). A change a super administrator asked for ends the person's other sessions --
// "remember me" ones too. The address it replaces, when verified, is told. Called with the user row locked.
class ApplyEmailChangeAction extends Action
{
    public function execute(User $locked): void
    {
        $previous = $locked->protectedEmailRecipient();
        $byTeam = $locked->pending_email_requested_by !== null;
        $new = $locked->pending_email;

        $locked->forceFill([
            'email' => $new,
            'email_verified_at' => now(),
            'pending_email' => null,
            'pending_email_requested_at' => null,
            'pending_email_requested_by' => null,
            'pending_email_confirmed_at' => null,
            ...($byTeam ? ['session_version' => (int) $locked->session_version + 1, 'remember_token' => Str::random(60)] : []),
        ])->save();

        EmailChangedNotification::sendTo($previous, $locked->name ?: $locked->username, $new, byTeam: $byTeam, stage: EmailChangedNotification::APPLIED);

        Log::notice('Account email change applied', ['user_id' => $locked->id, 'requested_by_team' => $byTeam]);
    }
}
