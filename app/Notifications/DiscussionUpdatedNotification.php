<?php

namespace App\Notifications;

use App\Http\Resources\DiscussionResource;
use App\Models\Counsellor;
use App\Models\Discussion;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DiscussionUpdatedNotification extends Notification implements ShouldQueue
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
        $start = new Carbon($this->discussion->start_time);
        $startTime = $start->toDayDateTimeString();
        $duration = $start->diffInMinutes(new Carbon($this->discussion->end_time));
        [$type, $url] = $this->discussion->getNotificationActionData();
        $name = $notifiable::class == Counsellor::class ? $notifiable->getName() : $notifiable->name;
        
        return (new MailMessage)
            ->success()
            ->subject("'{$this->discussion->name}' Discussion")
            ->greeting("Hello {$name}!")
            ->line("The discussion with name: '{$this->discussion->name}' has been updated.")
            ->line("This discussion will start on {$startTime}. The discussion will last {$duration} minutes. Please take notice.")
            ->action("Visit {$type} Page", $url)
            ->line("You may receive other reminders before the starting time.")
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
        return (new BroadcastMessage([
            'discussion' => new DiscussionResource($this->discussion)
        ]));
    }

    public function broadcastType(): string
    {
        return 'discussion.updated';
    }
}
