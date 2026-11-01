<?php

namespace App\Notifications;

use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

// SCRUM-359: to a client whose counsellor has been suspended (or whose pending request to them was
// declined because of it). Deliberately neutral: never says "suspended" or why. Points to the
// next steps and to urgent help.
class CounsellorUnavailableNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private string $counsellorName,
        private ?CarbonInterface $until,
        private int $cancelledSessions = 0,
        private bool $requestDeclined = false,
    ) {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return $notifiable->email_verified_at ? ['database', 'mail'] : ['database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('Your counsellor is unavailable for now')
            ->greeting("Hello {$notifiable->name},")
            ->line("{$this->counsellorName} is unavailable on TalkTherapy ".($this->until ? 'until '.$this->until->toFormattedDateString().'.' : 'for now.'));

        if ($this->requestDeclined) {
            $mail->line('Your pending request to them has been declined, so you can ask another counsellor.');
        }

        if ($this->cancelledSessions) {
            $mail->line("{$this->cancelledSessions} upcoming session(s) with them have been cancelled. If you paid for a session, you can request a refund from it.");
        }

        return $mail
            ->line('Your therapy and its history stay as they are. You can wait, or end the therapy and find another counsellor.')
            ->action('Find a counsellor', route('home'))
            ->line('If you need support urgently, please contact your local emergency services or a crisis line.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'counsellor.unavailable',
            'counsellorName' => $this->counsellorName,
            'until' => $this->until?->toJSON(),
            'cancelledSessions' => $this->cancelledSessions,
            'requestDeclined' => $this->requestDeclined,
        ];
    }
}
