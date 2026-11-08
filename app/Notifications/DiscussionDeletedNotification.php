<?php

namespace App\Notifications;

use App\Contracts\ShownInNotificationCenter;
use App\Models\Counsellor;
use App\Models\Discussion;
use App\Support\NotificationEntry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DiscussionDeletedNotification extends Notification implements ShouldQueue, ShownInNotificationCenter
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
        $name = $notifiable::class == Counsellor::class ? $notifiable->getName() : $notifiable->name;
        [$type, $url] = $this->discussion->getNotificationActionData();

        return (new MailMessage)
            ->subject("'{$this->discussion->name}' Discussion")
            ->greeting("Hello {$name}!")
            ->line("The discussion with name: '{$this->discussion->name}' for '{$this->discussion->for->name}' {$type} has been deleted.")
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
            'discussion_id' => $this->discussion->id,
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        // SCRUM-374: a small fixed summary -- the same for every recipient, never a per-viewer
        // resource (payment status, dob) built for whoever happened to be signed in.
        return new BroadcastMessage([
            'discussion' => [
                'id' => $this->discussion->id,
                'name' => $this->discussion->name,
                'startTime' => $this->discussion->start_time,
                'endTime' => $this->discussion->end_time,
                'status' => $this->discussion->status,
            ],
        ]);
    }

    public function broadcastType(): string
    {
        return 'discussion.deleted';
    }

    // SCRUM-39 (TT-5.4b): in the notification center -- from the saved ids only, never more than the email.
    public static function present(array $data): NotificationEntry
    {
        return new NotificationEntry('A discussion was deleted');
    }
}
