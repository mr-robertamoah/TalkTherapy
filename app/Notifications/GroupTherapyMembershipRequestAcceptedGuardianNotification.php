<?php

namespace App\Notifications;

use App\Models\Counsellor;
use App\Models\GroupTherapy;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class GroupTherapyMembershipRequestAcceptedGuardianNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(private GroupTherapy $groupTherapy)
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
        return (new MailMessage)
            ->success()
            ->subject('Group Therapy Membership Request Accepted')
            ->greeting("Hello {$notifiable->name}!")
            ->line("Your ward's request to join the group therapy with name: '{$this->groupTherapy->name}' created by '{$this->creatorName()}' has been accepted.")
            ->action('Visit Group Therapy Page', url("group-therapies/{$this->groupTherapy->id}"))
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
            'group_therapy_id' => $this->groupTherapy->id,
        ];
    }

    // Same masking convention as GroupTherapyMembershipRequestAcceptedNotification -- don't
    // reveal an anonymous group's creator identity to the ward's guardian either.
    private function creatorName(): ?string
    {
        if ($this->groupTherapy->addedby_type == Counsellor::class) {
            return $this->groupTherapy->addedby?->getName();
        }

        return $this->groupTherapy->anonymous ? 'an anonymous user' : $this->groupTherapy->addedby?->name;
    }
}
