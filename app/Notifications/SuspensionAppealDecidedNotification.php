<?php

namespace App\Notifications;

use App\Enums\SuspensionAppealDecisionEnum;
use App\Models\Suspension;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

// SCRUM-359: the outcome of a person's appeal, with the deciding administrator's note (never
// their name).
class SuspensionAppealDecidedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private Suspension $suspension)
    {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return $notifiable->email_verified_at ? ['database', 'mail'] : ['database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $lifted = $this->suspension->appeal_decision == SuspensionAppealDecisionEnum::lifted;

        $mail = (new MailMessage)
            ->subject('Your appeal has been decided')
            ->greeting("Hello {$notifiable->name},")
            ->line($lifted
                ? 'Your appeal was accepted and your suspension has been lifted.'
                : 'Your appeal was reviewed and the suspension stays in place.')
            ->line('Note from the administrator: '.$this->suspension->appeal_note);

        return $lifted
            ? $mail->action('Go to TalkTherapy', route('home'))
            : $mail->line('Ends: '.SuspensionAppliedNotification::until($this->suspension));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'suspension.appeal.decided',
            'suspensionId' => $this->suspension->id,
            'decision' => $this->suspension->appeal_decision?->value,
        ];
    }
}
