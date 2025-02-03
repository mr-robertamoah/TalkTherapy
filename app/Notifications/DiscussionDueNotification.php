<?php

namespace App\Notifications;

use App\Models\Discussion;
use App\Models\Therapy;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DiscussionDueNotification extends Notification implements ShouldQueue
{
    use Queueable;

    private array $notificationActionData = [];

    /**
     * Create a new notification instance.
     */
    public function __construct(private Discussion $discussion)
    {
        $this->afterCommit();
        $this->notificationActionData = $this->discussion->getNotificationActionData();
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        $via = ['database', 'broadcast'];

        if ($notifiable->email_verified_at)
            $via[] = 'mail';

        return $via;
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $name = $notifiable->getName();
        
        return (new MailMessage)
            ->success()
            ->subject("'{$this->discussion->name}' Discussion")
            ->greeting("Hello {$name}!")
            ->line("The discussion with name: '{$this->discussion->name}' which was created for " . "'{$this->discussion->for->name}' " . $this->notificationActionData[0] . " is about to start.")
            ->line("This discussion will be starting in less than 30 minutes. Please do not disappoint. Be on time.")
            ->action("Visit {$this->notificationActionData[0]} Page now", $this->notificationActionData[1])
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
            'discussion_id' => $this->discussion->id,
        ];
    }
    
    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage([
            'forName' => $this->discussion->for->name,
            'forId' => $this->discussion->for_id,
            'forType' => $this->notificationActionData[0],
            'discussion' => [
                'id' => $this->discussion->id,
                'name' => $this->discussion->name,
            ]
        ]);
    }

    public function broadcastType(): string
    {
        return 'discussion.due';
    }
}
