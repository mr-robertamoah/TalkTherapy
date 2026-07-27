<?php

namespace App\Notifications;

use App\Models\Request;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class GroupTherapyMembershipRequestRejectedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(private Request $request)
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
        $groupTherapy = $this->request->for;
        $reason = $this->request->data['reason'] ?? null;

        return (new MailMessage)
            ->error()
            ->subject('Group Therapy Membership Request Declined')
            ->greeting("Hello {$notifiable->name}!")
            ->line($reason
                ? "Your request to join the group therapy with name: '{$groupTherapy->name}' was declined. {$reason}"
                : "Your request to join the group therapy with name: '{$groupTherapy->name}' was declined.")
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
            'group_therapy_id' => $this->request->for_id,
        ];
    }
}
