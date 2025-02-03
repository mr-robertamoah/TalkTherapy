<?php

namespace App\Notifications;

use App\Models\Discussion;
use App\Models\Therapy;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DiscussionCounsellorRemovedNotification extends Notification implements ShouldQueue
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
        return ['mail'];
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
            ->line("You have been removed from the discussion with name: '{$this->discussion->name}' on TalkTherapy app.")
            ->line("You may not have access to the {$type} anymore.")
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
    
    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage([
            'forName' => $this->discussion->for->name,
            'forType' => $this->discussion->for_type == Therapy::class ? 'Therapy' : 'Group Therapy',
        ]);
    }

    public function broadcastType(): string
    {
        return 'discussion.counsellor.removed';
    }
}
