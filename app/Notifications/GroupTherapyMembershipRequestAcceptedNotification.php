<?php

namespace App\Notifications;

use App\Models\Counsellor;
use App\Models\Request;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class GroupTherapyMembershipRequestAcceptedNotification extends Notification implements ShouldQueue
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
        $groupTherapy = $this->request->for;

        return (new MailMessage)
            ->success()
            ->subject('Group Therapy Membership Request Accepted')
            ->greeting("Hello {$notifiable->name}!")
            ->line("Your request to join the group therapy with name: '{$groupTherapy->name}' created by '{$this->creatorName($groupTherapy)}' has been accepted.")
            ->action('Visit Group Therapy Page', url("group-therapies/{$groupTherapy->id}"))
            ->line('You may receive other reminders before its next session.')
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

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        $groupTherapy = $this->request->for;

        return new BroadcastMessage([
            'groupTherapy' => [
                'id' => $groupTherapy->id,
                'name' => $groupTherapy->name,
            ],
        ]);
    }

    public function broadcastType(): string
    {
        return 'group.therapy.membership.accepted';
    }

    // Anonymity only ever applies to a User (client) creator, never a Counsellor one, mirroring
    // GroupTherapyResource's own "mask addedby when the group is anonymous" convention -- don't
    // reveal the creator's identity to a newly-accepted member of an anonymous group.
    private function creatorName($groupTherapy): ?string
    {
        if ($groupTherapy->addedby_type == Counsellor::class) {
            return $groupTherapy->addedby?->getName();
        }

        return $groupTherapy->anonymous ? 'an anonymous user' : $groupTherapy->addedby?->name;
    }
}
