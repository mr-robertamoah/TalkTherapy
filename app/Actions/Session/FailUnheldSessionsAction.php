<?php

namespace App\Actions\Session;

use App\Actions\Action;
use App\Enums\SessionStatusEnum;
use App\Models\Session;
use App\Notifications\SessionFailedNotification;
use Illuminate\Support\Facades\Notification;

// Scheduled every four hours: a session past its end that was never held is marked failed, and its
// participants are told. SCRUM-374: moved out of AppService.
class FailUnheldSessionsAction extends Action
{
    public function execute(): void
    {
        Session::query()
            ->whereStatusIn([
                SessionStatusEnum::pending->value,
                SessionStatusEnum::in_session_confirmation->value,
            ])
            ->wherePastEndTime()
            ->get()
            ->each(function (Session $session) {
                Notification::send($session->users, new SessionFailedNotification($session));

                $session->status = SessionStatusEnum::failed->value;
                $session->save();
            });
    }
}
