<?php

namespace App\Actions\Session;

use App\Actions\Action;
use App\Enums\SessionStatusEnum;
use App\Enums\SessionTypeEnum;
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
            // SCRUM-445: an in-person session waits until its attendance window has closed -- its counsellor may still
            // mark everyone absent and close it as a no-show, which a FAILED register would no longer allow.
            ->where(fn ($q) => $q->where('type', '!=', SessionTypeEnum::in_person->value)
                ->orWhere('end_time', '<', now()->subHours((int) config('limits.attendance_window_hours'))))
            ->get()
            ->each(function (Session $session) {
                Notification::send($session->users, new SessionFailedNotification($session));

                $session->status = SessionStatusEnum::failed->value;
                $session->save();
            });
    }
}
