<?php

namespace App\Notifications;

use App\Contracts\ShownInNotificationCenter;
use App\Enums\ConstantsEnum;
use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Models\User;
use App\Support\NotificationEntry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

// To a minor's guardians when the minor becomes a member of a group (AddGroupTherapyMemberAction).
// SCRUM-73: renamed from GroupTherapyMembershipRequestAcceptedGuardianNotification, as it is now sent
// for every way of joining. $joinedVia picks the wording: an accepted request reads "accepted"; the
// join button and an invitation link read "joined" (there was nothing to accept).
class GroupTherapyMemberJoinedGuardianNotification extends Notification implements ShouldQueue, ShownInNotificationCenter
{
    use Queueable;

    public const VIA_BUTTON = 'button';

    public const VIA_LINK = 'link';

    public const VIA_REQUEST = 'request';

    public function __construct(private GroupTherapy $groupTherapy, private string $joinedVia = self::VIA_REQUEST)
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
        $accepted = $this->joinedVia === self::VIA_REQUEST;

        return (new MailMessage)
            ->success()
            ->subject($accepted ? 'Group Therapy Membership Request Accepted' : 'Your Ward Joined a Group Therapy')
            ->greeting("Hello {$notifiable->name}!")
            ->line($accepted
                ? "Your ward's request to join the group therapy with name: '{$this->groupTherapy->name}' created by '{$this->creatorName($notifiable)}' has been accepted."
                : "Your ward has joined the group therapy with name: '{$this->groupTherapy->name}' created by '{$this->creatorName($notifiable)}'.")
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
    private function creatorName(object $notifiable): ?string
    {
        if ($this->groupTherapy->addedby_type == Counsellor::class) {
            return $this->groupTherapy->addedby?->getName();
        }

        // SCRUM-56: the same rule GroupTherapyResource uses (addedByUserIsMaskedFor). A deleted
        // creator is never named either.
        return ! $this->groupTherapy->addedby || $this->groupTherapy->addedByUserIsMaskedFor($notifiable instanceof User ? $notifiable : null)
            ? ConstantsEnum::anonymousPersonPhrase->value
            : $this->groupTherapy->addedby->name;
    }

    // SCRUM-39 (TT-5.4b): in the notification center -- from the saved ids only, never more than the email.
    public static function present(array $data): NotificationEntry
    {
        return new NotificationEntry('Your ward joined a group therapy', '', NotificationEntry::link('group.therapies.get', ['groupTherapyId' => $data['group_therapy_id'] ?? null]));
    }
}
