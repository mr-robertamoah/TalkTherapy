<?php

namespace App\Notifications;

use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Support\TherapyLink;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

// SCRUM-383: a counsellor joined a group through its counsellor invitation link -- to the group's
// creator (who issued it) and, for a minor creator, to their guardians ($toGuardian), as
// PerformTherapyCounsellorLinkAction does for a 1:1. Counsellors are never anonymous, so they are
// named; the creator never is (a guardian is only told it is their ward's group).
class GroupTherapyCounsellorLinkUsedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private GroupTherapy $groupTherapy, private Counsellor $counsellor, private bool $toGuardian = false)
    {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return $notifiable->email_verified_at ? ['database', 'mail'] : ['database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $name = $this->counsellor->getName();

        return (new MailMessage)
            ->success()
            ->subject($this->toGuardian ? 'A counsellor joined your ward\'s group therapy' : 'Your counsellor invitation link was used')
            ->greeting("Hello {$notifiable->name},")
            ->line($this->toGuardian
                ? "Counsellor {$name} has joined the group therapy \"{$this->groupTherapy->name}\" your ward created."
                : "Counsellor {$name} used your invitation link and is now a counsellor of \"{$this->groupTherapy->name}\".")
            ->action('Open the group', TherapyLink::to($this->groupTherapy));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => $this->toGuardian ? 'group_therapy.ward_counsellor_joined' : 'group_therapy.counsellor_link_used',
            ...TherapyLink::ids($this->groupTherapy),
            'counsellorId' => $this->counsellor->id,
        ];
    }
}
