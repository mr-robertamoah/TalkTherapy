<?php

namespace App\Notifications;

use App\Contracts\ShownInNotificationCenter;
use App\Enums\SuspensionScopeEnum;
use App\Models\Suspension;
use App\Support\NotificationEntry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

// SCRUM-359: tells a guardian that the minor in their care has been suspended, and until when.
// The reason is the minor's to share; the guardian is pointed to them and to support.
class WardSuspendedNotification extends Notification implements ShouldQueue, ShownInNotificationCenter
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
        $what = $this->suspension->scope == SuspensionScopeEnum::account ? 'TalkTherapy account' : 'counsellor role on TalkTherapy';
        $ward = $this->suspension->user;

        return (new MailMessage)
            ->subject('A TalkTherapy account in your care has been suspended')
            ->greeting("Hello {$notifiable->name},")
            ->line("As {$ward->name}'s guardian, we are letting you know that their {$what} has been suspended following a review.")
            ->line('Ends: '.SuspensionAppliedNotification::until($this->suspension))
            ->line('They have been told why and how to appeal.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'suspension.ward',
            'suspensionId' => $this->suspension->id,
            'wardId' => $this->suspension->user_id,
        ];
    }

    // SCRUM-39: in the notification center.
    public static function present(array $data): NotificationEntry
    {
        return new NotificationEntry('An account in your care has been suspended', 'They have been told why and how to appeal.');
    }
}
