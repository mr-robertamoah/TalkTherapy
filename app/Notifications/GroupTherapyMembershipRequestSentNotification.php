<?php

namespace App\Notifications;

use App\Models\Request;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class GroupTherapyMembershipRequestSentNotification extends Notification implements ShouldQueue
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
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        // Mask the requester's identity if either the group itself defaults everyone to
        // anonymous, or the requester chose to join anonymously -- follows the same
        // anonymous-therapy PII-safety convention as TT-1.5/SCRUM-71: don't reveal an identity
        // that's about to become anonymous once the request is accepted.
        $isAnonymous = $this->request->for->anonymous || (bool) ($this->request->data['anonymous'] ?? false);

        $fromName = $isAnonymous ? 'an anonymous user' : $this->request->from->name;

        return (new MailMessage)
            ->success()
            ->subject('Group Therapy Membership Request')
            ->greeting("Hello {$notifiable->name}!")
            ->line("A user with name: '{$fromName}' has requested to join your group therapy with name: '{$this->request->for->name}'. Check your requests to accept or decline.")
            ->action('Visit Group Therapy Page', url("group-therapies/{$this->request->for->id}"))
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
}
