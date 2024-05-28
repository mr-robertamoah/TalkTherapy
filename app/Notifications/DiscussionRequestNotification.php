<?php

namespace App\Notifications;

use App\Models\Discussion;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DiscussionRequestNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(private Discussion $discussion)
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
        [$type, $url] = $this->discussion->getNotificationActionData();

        return (new MailMessage)
            ->success()
            ->subject("Discussion Request")
            ->greeting("Hello {$notifiable->getName()}!")
            ->line("A counsellor with name: '{$this->discussion->addedby->getName()}' has sent you a request inviting you to be part of a discussion on TalkTherapy app.")
            ->line("'{$this->discussion->name}' is the name of the discussion.")
            ->line("Click on the link below to check out the therapy/group therapy.")
            ->action("Visit {$type} Page", $url)
            ->line("Please make sure to you check your pending requests in other to respond to this request.")
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
            'discussion_id' => $this->discussion->id
        ];
    }
}
