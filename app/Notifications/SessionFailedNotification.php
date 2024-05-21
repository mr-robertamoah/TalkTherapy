<?php

namespace App\Notifications;

use App\Models\Session;
use App\Models\Therapy;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SessionFailedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(private Session $session)
    {
        //
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        [$type, $url] = $this->session->getNotificationActionData();

        return (new MailMessage)
            ->error()
            ->subject("Session Failed.")
            ->line("For some reason the session with name: '{$this->session->name}' for {$type} with name: {$this->session->for->name} failed to happen.")
            ->action("Visit {$type} Page", $url)
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
            //
        ];
    }
}
