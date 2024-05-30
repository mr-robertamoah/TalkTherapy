<?php

namespace App\Notifications;

use App\Http\Resources\UserMiniResource;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class GuardianshipRemovedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(private User $user)
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
        return (new MailMessage)
            ->success()
            ->subject("Guardianship Removed")
            ->greeting("Hello {$notifiable->name}!")
            ->line("User with name: '{$this->user->name}' and username: '{$this->user->username}', has removed his/her guardianship with you and hence no more your guardian on TalkTherapy app.")
            ->line("Click on the link below to go to your profile page on TalkTherapy, if you are not already on the app.")
            ->action("Visit Profile", url('profile'))
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
            'userId' => $this->user->id,
        ];
    }
    
    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return (new BroadcastMessage([
            'user' => new UserMiniResource($this->user)
        ]));
    }

    public function broadcastType(): string
    {
        return 'user.guardianship';
    }
}
