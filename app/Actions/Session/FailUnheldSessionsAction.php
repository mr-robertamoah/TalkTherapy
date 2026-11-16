<?php

namespace App\Actions\Session;

use App\Actions\Action;
use App\Enums\SessionStatusEnum;
use App\Enums\SessionTypeEnum;
use App\Models\GroupTherapy;
use App\Models\Session;
use App\Models\Therapy;
use App\Notifications\SessionFailedNotification;
use Illuminate\Database\Eloquent\Relations\MorphTo;
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
            ->with(['for' => fn (MorphTo $morphTo) => $morphTo->morphWith([
                Therapy::class => ['addedby', 'counsellor.user'],
                GroupTherapy::class => ['addedby', 'users'],
            ])])
            ->get()
            ->each(function (Session $session) {
                Notification::send($session->users, new SessionFailedNotification($session));

                $session->status = SessionStatusEnum::failed->value;
                // SCRUM-448: ended, like any other way a session finishes (ChangeSessionStatusAction).
                $session->ended_at ??= now();
                $session->save();
            });
    }
}
