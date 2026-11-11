<?php

namespace App\Notifications;

use App\Contracts\ShownInNotificationCenter;
use App\Enums\OrganizationMemberSourceEnum;
use App\Models\Organization;
use App\Support\NotificationEntry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

// SCRUM-416: to a minor's guardians when the minor becomes a member of an organisation
// (CreateOrganizationMemberAction) -- by their application being accepted, or by accepting the
// organisation's invitation. Like GroupTherapyMemberJoinedGuardianNotification. No link: a guardian has no
// page of the organisation to open.
class OrganizationMemberJoinedGuardianNotification extends Notification implements ShouldQueue, ShownInNotificationCenter
{
    use Queueable;

    public function __construct(private Organization $organization, private string $source)
    {
        $this->afterCommit();
    }

    /**
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

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your Ward Joined an Organization')
            ->greeting("Hello {$notifiable->name}!")
            ->line($this->source === OrganizationMemberSourceEnum::invited->value
                ? "Your ward accepted an invitation to become a member of the organization '{$this->organization->name}'."
                : "Your ward's request to become a member of the organization '{$this->organization->name}' has been accepted.")
            ->line('An organization can pay for its members\' therapy. If you have questions, talk with your ward.')
            ->line("Thank you for choosing to 'TalkTherapy'.");
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'organization_id' => $this->organization->id,
            'organization_name' => $this->organization->name,
            'source' => $this->source,
        ];
    }

    public static function present(array $data): NotificationEntry
    {
        return new NotificationEntry(
            'Your ward joined an organization',
            'They are now a member of '.NotificationEntry::text($data, 'organization_name', 'an organization').'.',
        );
    }
}
