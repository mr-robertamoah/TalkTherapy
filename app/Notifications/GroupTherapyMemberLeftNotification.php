<?php

namespace App\Notifications;

use App\Contracts\ShownInNotificationCenter;
use App\Models\GroupTherapy;
use App\Models\User;
use App\Support\NotificationEntry;
use App\Support\TherapyLink;
use App\Support\WardRef;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

// SCRUM-73: to a group's creator and its active counsellors when a member leaves. The member is
// named exactly as the group shows them ($memberName, from displayNameOf() taken before they left,
// so an anonymous member stays "Anonymous"), and never by id (SCRUM-345). The stored notice carries
// them as an opaque WardRef, so anonymising their closed account can replace $memberName in it
// (AnonymiseClosedAccountAction).
class GroupTherapyMemberLeftNotification extends Notification implements ShouldQueue, ShownInNotificationCenter
{
    use Queueable;

    private string $wardRef;

    public function __construct(private GroupTherapy $groupTherapy, private string $memberName, User $member)
    {
        $this->wardRef = WardRef::of($member);
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return $notifiable->email_verified_at ? ['database', 'mail'] : ['database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('A member left your group')
            ->greeting("Hello {$notifiable->name},")
            ->line("{$this->memberName} has left the group \"{$this->groupTherapy->name}\".")
            ->line('Their earlier messages stay in the group\'s history. Their place is free for someone else.')
            ->action('Open the group', TherapyLink::to($this->groupTherapy));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'group_therapy.member_left',
            ...TherapyLink::ids($this->groupTherapy),
            'memberName' => $this->memberName,
            WardRef::KEY => $this->wardRef,
        ];
    }

    // SCRUM-39: in the notification center.
    public static function present(array $data): NotificationEntry
    {
        $name = NotificationEntry::text($data, 'memberName', 'A member');

        return new NotificationEntry("{$name} left your group", 'Their place is free for someone else.', TherapyLink::fromIds($data));
    }
}
