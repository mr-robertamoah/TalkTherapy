<?php

namespace App\Notifications;

use App\Contracts\SentOnlyToSuperAdministrators;
use App\Contracts\ShownInNotificationCenter;
use App\Models\Suspension;
use App\Support\NotificationEntry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

// SCRUM-359: to the super administrators who may decide a new appeal. Carries no appeal text --
// that stays behind the admin page.
class SuspensionAppealSubmittedNotification extends Notification implements SentOnlyToSuperAdministrators, ShouldQueue, ShownInNotificationCenter
{
    use Queueable;

    public function __construct(private Suspension $suspension)
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
            ->subject('A suspension has been appealed')
            ->line('Someone has appealed their suspension. An administrator who did not apply it needs to decide the appeal.')
            ->action('Review appeals', route('administrator.suspensions'));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'suspension.appealed',
            'suspensionId' => $this->suspension->id,
        ];
    }

    // SCRUM-39: in the notification center.
    public static function present(array $data): NotificationEntry
    {
        return new NotificationEntry('A suspension has been appealed', 'An administrator who did not apply it needs to decide the appeal.', NotificationEntry::link('administrator.suspensions', []));
    }
}
