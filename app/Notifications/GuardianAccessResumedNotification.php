<?php

namespace App\Notifications;

use App\Contracts\ShownInNotificationCenter;
use App\Models\SafeguardingException;
use App\Support\NotificationEntry;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

// SCRUM-288: to a paused minor whose account has an active guardian again. SCRUM-382: the
// counsellor's side is ClientAccessResumedNotification, mirroring the pause side.
// SCRUM-381: $continuesUntil -- it is a super administrator's safeguarding exception that lets them
// carry on, until then. Neutral: it doesn't say "a guardian again" (they have none), and never gives
// the reason or anything about the family.
class GuardianAccessResumedNotification extends Notification implements ShouldQueue, ShownInNotificationCenter
{
    use Queueable;

    public function __construct(private ?CarbonInterface $continuesUntil = null)
    {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return $notifiable->email_verified_at ? ['database', 'mail'] : ['database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        if ($this->continuesUntil) {
            $until = SafeguardingException::formatEnd($this->continuesUntil);

            return (new MailMessage)
                ->subject('Your therapy on TalkTherapy can continue')
                ->greeting("Hello {$notifiable->name},")
                ->line("Your therapy can continue for now, until {$until}. You can send messages and book sessions as before. Joining video calls still needs a parent or guardian's consent.")
                ->line('Adding a guardian to your account keeps it going after that date.')
                ->action('Go to TalkTherapy', route('home'))
                ->line('If you need support urgently, please contact your local emergency services or a crisis line.');
        }

        return (new MailMessage)
            ->subject('Your therapy on TalkTherapy has started again')
            ->greeting("Hello {$notifiable->name},")
            ->line('Your account has a guardian again, so your therapy is no longer paused. You can send messages, book sessions and join calls as before.')
            ->action('Go to TalkTherapy', route('home'));
    }

    public function toArray(object $notifiable): array
    {
        return array_filter([
            'type' => 'guardian_access.resumed',
            'continuesUntil' => $this->continuesUntil?->toJSON(),
        ]);
    }

    // SCRUM-39: in the notification center.
    public static function present(array $data): NotificationEntry
    {
        $until = NotificationEntry::date($data['continuesUntil'] ?? null);

        return $until
            ? new NotificationEntry('Your therapy can continue', "For now, until {$until}. Adding a guardian keeps it going after that.".NotificationEntry::CRISIS_LINE)
            : new NotificationEntry('Your therapy has started again');
    }
}
