<?php

namespace App\Notifications;

use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

// SCRUM-31: to a counsellor once their verification has ended (RecordEndedCounsellorVerificationsAction).
class CounsellorVerificationEndedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private CarbonInterface $expiresAt)
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
            ->subject('Your TalkTherapy verification has ended')
            ->greeting("Hello {$notifiable->getName()},")
            ->line("Your counsellor verification ended on {$this->expiresAt->toFormattedDateString()}.")
            ->line('Your current clients and sessions carry on. Until you re-verify, you can\'t take on new clients, join groups as a counsellor, post, or link to an organisation.')
            ->action('Re-verify now', route('counsellor.show', ['counsellorId' => $notifiable->id]).'?verify=1');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'counsellor_verification.ended',
            'expiresAt' => $this->expiresAt->toJSON(),
        ];
    }
}
