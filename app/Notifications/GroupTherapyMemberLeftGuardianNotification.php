<?php

namespace App\Notifications;

use App\Contracts\ShownInNotificationCenter;
use App\Models\GroupTherapy;
use App\Support\NotificationEntry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

// SCRUM-73: to a minor's guardians when the minor leaves a group. Neutral: the group's name and that
// they left, nothing about anyone else in it.
class GroupTherapyMemberLeftGuardianNotification extends Notification implements ShouldQueue, ShownInNotificationCenter
{
    use Queueable;

    public function __construct(private GroupTherapy $groupTherapy)
    {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return $notifiable->email_verified_at ? ['database', 'mail'] : ['database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your ward left a group therapy')
            ->greeting("Hello {$notifiable->name},")
            ->line("Your ward has left the group therapy \"{$this->groupTherapy->name}\".")
            ->line('They can join it again later, following the group\'s usual joining rules.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'group_therapy.ward_left',
            'groupTherapyId' => $this->groupTherapy->id,
        ];
    }

    // SCRUM-39: in the notification center.
    public static function present(array $data): NotificationEntry
    {
        return new NotificationEntry('Your ward left a group therapy', 'They can join it again later.');
    }
}
