<?php

namespace App\Notifications;

use App\Contracts\ShownInNotificationCenter;
use App\Models\User;
use App\Support\NotificationEntry;
use App\Support\WardRef;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

// SCRUM-359: to a client whose counsellor has been suspended (or whose pending request to them was
// declined because of it). Deliberately neutral: never says "suspended" or why. Points to the
// next steps and to urgent help.
class CounsellorUnavailableNotification extends Notification implements ShouldQueue, ShownInNotificationCenter
{
    use Queueable;

    public function __construct(
        private string $counsellorName,
        private ?CarbonInterface $until,
        private int $cancelledSessions = 0,
        private bool $requestDeclined = false,
        // SCRUM-396: who it names, so anonymising their closed account renames them here (WardRef).
        ?User $counsellorUser = null,
    ) {
        $this->wardRef = $counsellorUser ? WardRef::of($counsellorUser) : null;
        $this->afterCommit();
    }

    private ?string $wardRef;

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
            ...($this->wardRef ? [WardRef::KEY => $this->wardRef] : []),
        ];
    }

    // SCRUM-39: in the notification center.
    public static function present(array $data): NotificationEntry
    {
        $name = NotificationEntry::personName($data, 'counsellorName', 'Your counsellor');
        $until = NotificationEntry::date($data['until'] ?? null);

        $declined = ! empty($data['requestDeclined']) ? ' Your pending request to them was declined.' : '';
        $cancelled = (int) ($data['cancelledSessions'] ?? 0) > 0 ? ' Upcoming sessions with them were cancelled.' : '';

        return new NotificationEntry("{$name} is unavailable", ($until ? "Until {$until}." : 'For now.').$declined.$cancelled.NotificationEntry::CRISIS_LINE);
    }
}
