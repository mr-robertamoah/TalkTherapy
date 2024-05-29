<?php

namespace App\Notifications;

use App\Models\Therapy;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TherapyCreatedNotification extends Notification
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(private Therapy $therapy)
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

        if ($notifiable->email_verified_at)
            $via[] = 'mail';
        
        return $via;
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->success()
            ->subject("Therapy Created by Ward")
            ->greeting("Hello {$notifiable->name}!")
            ->line("User with name: '{$this->therapy->addedby->name}' and username: '{$this->therapy->addedby->username}', has created a therapy with name: '{$this->therapy->name}' on TalkTherapy app.")
            ->line("Click on the link below to go have a look at the therapy page since you are a guardian of the user.")
            ->action("Visit Therapy Page", url("therapies/{$this->therapy->id}"))
            ->line("Thank you for choosing to 'TalkTherapy'.");
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'therapy_id' => $this->therapy->id
        ];
    }
}
