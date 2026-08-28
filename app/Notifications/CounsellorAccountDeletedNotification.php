<?php

namespace App\Notifications;

use App\Models\Counsellor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

// SCRUM-134: sent to every former client (therapy/group therapy participant) of a counsellor
// whose account has been deleted, so they aren't left silently wondering why the counsellor's
// profile disappeared.
class CounsellorAccountDeletedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Counsellor $counsellor) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('A counsellor you worked with has left TalkTherapy')
            ->greeting("Hello {$notifiable->name}!")
            ->line("{$this->counsellor->getName()}, a counsellor you previously worked with, has deleted their counsellor account.")
            ->line('Any therapies or group therapies you had with them remain in your history, but they will no longer be available as a counsellor on the platform.')
            ->action('Visit TalkTherapy', route('home'))
            ->line("Thank you for choosing 'TalkTherapy'.");
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [];
    }
}
