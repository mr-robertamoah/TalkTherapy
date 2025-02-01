<?php

namespace App\Notifications;

use App\Enums\DiscussionStatusEnum;
use App\Models\Counsellor;
use App\Models\Discussion;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DiscussionStatusChangedNotification extends Notification implements ShouldQueue
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
        $name = $notifiable::class == Counsellor::class ? $notifiable->getName() : $notifiable->name;
        
        return (new MailMessage)
            ->success()
            ->subject("'{$this->discussion->name}' Discussion")
            ->greeting("Hello {$name}!")
            ->line("The status for the discussion with name: '{$this->discussion->name}' changed.")
            ->line("This discussion {$this->getDiscussionText()}")
            ->action("Visit {$type} Page now", $url)
            ->line("Thank you for choosing to 'TalkTherapy'.");
    }

    private function getDiscussionText()
    {
        return match ($this->discussion->status) {
            DiscussionStatusEnum::abandoned->value => "has been abandoned. The counsellor who created the discussion has ended before its end time.",
            DiscussionStatusEnum::in_session->value => "has started.",
            DiscussionStatusEnum::held->value => "has ended.",
            DiscussionStatusEnum::failed->value => "has been failed. Meaning it did not take place.",
            DiscussionStatusEnum::held_confirmation->value => "requires you to confirm that the discussion has ended. Please visit page and click the 'end discussion' button.",
            DiscussionStatusEnum::in_session_confirmation->value => "requires you to confirm that the discussion has started. Please visit page and click the 'start discussion' button.",
            default => "is currently pending.",
        };
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
