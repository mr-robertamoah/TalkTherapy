<?php

namespace App\Notifications;

use App\Http\Resources\UserMiniResource;
use App\Models\Counsellor;
use App\Models\Discussion;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DiscussionInclusionNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(private Counsellor $counsellor, private Discussion $discussion)
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
            ->line("Counsellor with name: '{$this->counsellor->getName()}' has accepted your request to take part in the discussion with name: '{$this->discussion->name}' on TalkTherapy app.")
            ->line("Click on the link below to go the {$type}.")
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
            'counsellor_id' => $this->counsellor->id,
        ];
    }
}
