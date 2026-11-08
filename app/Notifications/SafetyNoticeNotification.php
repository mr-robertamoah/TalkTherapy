<?php

namespace App\Notifications;

use App\Contracts\ShownInNotificationCenter;
use App\Support\NotificationEntry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

// SCRUM-32 (TT-4.3c): the neutral notice to the person a safety flag is about -- in the app only (never by
// email: the user's decision), shown as a notice they dismiss (GetSafetyNoticeAction). Nothing about the
// flag: no reason, counsellor, therapy, or that a flag exists. The wording is pending the legal read.
class SafetyNoticeNotification extends Notification implements ShouldQueue, ShownInNotificationCenter
{
    use Queueable;

    // What the person reads (the dialog now; SCRUM-395's notification list later) -- one place for the legal read.
    public const MESSAGE = 'The TalkTherapy team wants to make sure you have the support you need right now. If you are in danger or thinking about harming yourself, please contact emergency services now, or use "Get help now" to talk to someone.';

    public static function message(array $data = []): string
    {
        return self::MESSAGE;
    }

    public function __construct()
    {
        $this->afterCommit();
    }

    public function shouldSend(object $notifiable, string $channel): bool
    {
        return (bool) config('safety.enabled');
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return ['type' => 'safety.notice'];
    }

    // SCRUM-39: in the notification center.
    public static function present(array $data): NotificationEntry
    {
        return new NotificationEntry('A message from TalkTherapy', self::message($data));
    }
}
