<?php

namespace App\Notifications;

use App\Models\Therapy;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TherapyAssistanceRequestAcceptedGuardianNotification extends Notification implements ShouldQueue
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
            ->subject("Assistance Request Accepted")
            ->greeting("Hello {$notifiable->name}!")
            ->line("The request of your ward with name: '{$this->therapy->addedby->name}', for assistance for the therapy with name: '{$this->therapy->name}' has been accepted by counsellor with name: '{$this->therapy->cpunsellor->getName()}'.")
            ->action("Visit Counsellor Page", url("counsellors/{$this->therapy->counsellor_id}"))
            ->line("You may receive other reminders before the starting time.")
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
            'therapy_id' => $this->therapy->id,
        ];
    }
}
