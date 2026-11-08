<?php

namespace App\Notifications;

use App\Contracts\ShownInNotificationCenter;
use App\Support\NotificationEntry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

// SCRUM-288: to a minor whose therapies are paused because their account has no active guardian.
// Neutral: never says what happened to the guardian, or who they were. Says what still works, how
// it starts again, and where to find urgent help (the same wording as the rest of the app).
class GuardianAccessPausedNotification extends Notification implements ShouldQueue, ShownInNotificationCenter
{
    use Queueable;

    public function __construct()
    {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return $notifiable->email_verified_at ? ['database', 'mail'] : ['database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your therapy on TalkTherapy is paused')
            ->greeting("Hello {$notifiable->name},")
            ->line('Your account no longer has a parent or guardian linked to it, so your therapy is paused for now.')
            ->line('You can still read your past messages and sessions. Sending messages, booking or starting sessions and joining calls are on hold. Your upcoming sessions stay booked.')
            ->line('It starts again on its own as soon as a guardian is added to your account.')
            ->action('Add a guardian', route('profile.show'))
            ->line('If you need support urgently, please contact your local emergency services or a crisis line.');
    }

    public function toArray(object $notifiable): array
    {
        return ['type' => 'guardian_access.paused'];
    }

    // SCRUM-39: in the notification center.
    public static function present(array $data): NotificationEntry
    {
        return new NotificationEntry('Your therapy is paused', 'Your account no longer has a guardian. It starts again when one is added.');
    }
}
