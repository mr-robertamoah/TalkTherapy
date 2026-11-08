<?php

namespace App\Notifications;

use App\Contracts\ShownInNotificationCenter;
use App\Models\User;
use App\Support\NotificationEntry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class GuardianshipRemovedNotification extends Notification implements ShouldQueue, ShownInNotificationCenter
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
        return (new MailMessage)
            ->success()
            ->subject('Guardianship Removed')
            ->greeting("Hello {$notifiable->name}!")
            ->line("User with name: '{$this->user->name}' and username: '{$this->user->username}', has removed his/her guardianship with you and hence no more your guardian on TalkTherapy app.")
            ->line('Click on the link below to go to your profile page on TalkTherapy, if you are not already on the app.')
            ->action('Visit Profile', url('profile'))
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
        // SCRUM-374: a small fixed summary -- the same for every recipient, never a per-viewer
        // resource (payment status, dob) built for whoever happened to be signed in.
        return new BroadcastMessage([
            'user' => [
                'id' => $this->user->id,
                'name' => $this->user->name,
            ],
        ]);
    }

    public function broadcastType(): string
    {
        return 'user.guardianship';
    }

    // SCRUM-39 (TT-5.4b): in the notification center -- from the saved ids only, never more than the email.
    public static function present(array $data): NotificationEntry
    {
        return new NotificationEntry('A guardian removed their guardianship', '', NotificationEntry::link('profile.show', []));
    }
}
