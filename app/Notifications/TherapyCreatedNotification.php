<?php

namespace App\Notifications;

use App\Contracts\ShownInNotificationCenter;
use App\Models\GroupTherapy;
use App\Models\Therapy;
use App\Support\NotificationEntry;
use App\Support\TherapyLink;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TherapyCreatedNotification extends Notification implements ShouldQueue, ShownInNotificationCenter
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(private Therapy|GroupTherapy $therapy)
    {
        $this->afterCommit();
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        $via = ['database'];

        if ($notifiable->email_verified_at) {
            $via[] = 'mail';
        }

        return $via;
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->success()
            ->subject('Therapy Created by Ward')
            ->greeting("Hello {$notifiable->name}!")
            ->line($this->therapy->anonymous
                ? "An anonymous user has created a therapy with name: '{$this->therapy->name}' on TalkTherapy app."
                : "User with name: '{$this->therapy->addedby->name}' and username: '{$this->therapy->addedby->username}', has created a therapy with name: '{$this->therapy->name}' on TalkTherapy app.")
            ->line('Click on the link below to go have a look at the therapy page since you are a guardian of the user.')
            ->action('Visit Therapy Page', TherapyLink::to($this->therapy))
            ->line("Thank you for choosing to 'TalkTherapy'.");
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        // TherapyLink's keys (SCRUM-39): it's sent for groups too, and therapy_id once held a group's id, so
        // an old row gets no link.
        return TherapyLink::ids($this->therapy);
    }

    // SCRUM-39 (TT-5.4b): in the notification center -- from the saved ids only, never more than the email.
    public static function present(array $data): NotificationEntry
    {
        return new NotificationEntry(isset($data['groupTherapyId']) ? 'Your ward started a group therapy' : 'Your ward started a therapy', '', TherapyLink::fromIds($data));
    }
}
