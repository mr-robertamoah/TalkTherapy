<?php

namespace App\Notifications;

use App\Contracts\ShownInNotificationCenter;
use App\Support\MailText;
use App\Support\NotificationEntry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

// SCRUM-31: to a counsellor whose verification an administrator revoked, with the reason they gave
// (never the administrator's name).
class CounsellorVerificationRevokedNotification extends Notification implements ShouldQueue, ShownInNotificationCenter
{
    use Queueable;

    public function __construct(private string $reason)
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
            ->subject('Your TalkTherapy verification was revoked')
            ->greeting("Hello {$notifiable->getName()},")
            ->line('An administrator has revoked your counsellor verification. The reason given:')
            ->line(MailText::inline($this->reason))
            ->line('Your current clients and sessions carry on. Until you are verified again, you can\'t take on new clients, join groups as a counsellor, post, or link to an organisation.')
            ->action('Request verification', route('counsellor.show', ['counsellorId' => $notifiable->id]).'?verify=1');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'counsellor_verification.revoked',
            'reason' => $this->reason,
        ];
    }

    // SCRUM-39: in the notification center.
    public static function present(array $data): NotificationEntry
    {
        return new NotificationEntry('Your verification was revoked', 'An administrator revoked your counsellor verification.'.(is_string($data['reason'] ?? null) && trim($data['reason']) !== '' ? ' Reason: '.trim($data['reason']) : ''), NotificationEntry::link('counsellor.dashboard', []));
    }
}
