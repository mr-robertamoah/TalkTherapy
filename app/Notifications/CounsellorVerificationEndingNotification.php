<?php

namespace App\Notifications;

use App\Contracts\ShownInNotificationCenter;
use App\Support\NotificationEntry;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

// SCRUM-31: to a counsellor, 30 and 7 days (config) before their verification ends -- once each
// (SendCounsellorVerificationRemindersAction). The link opens their profile's verify form.
class CounsellorVerificationEndingNotification extends Notification implements ShouldQueue, ShownInNotificationCenter
{
    use Queueable;

    public function __construct(private CarbonInterface $expiresAt, private int $daysLeft)
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
            ->subject('Your TalkTherapy verification ends soon')
            ->greeting("Hello {$notifiable->getName()},")
            ->line("Your counsellor verification ends on {$this->expiresAt->toFormattedDateString()}, in {$this->daysLeft} ".($this->daysLeft === 1 ? 'day' : 'days').'.')
            ->line('Re-verify before then to keep taking on new clients. You will need to upload your national ID again.')
            ->action('Re-verify now', route('counsellor.show', ['counsellorId' => $notifiable->id]).'?verify=1')
            ->line('Your current clients carry on either way.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'counsellor_verification.ending',
            'expiresAt' => $this->expiresAt->toJSON(),
            'daysLeft' => $this->daysLeft,
        ];
    }

    // SCRUM-39: in the notification center.
    public static function present(array $data): NotificationEntry
    {
        $date = NotificationEntry::date($data['expiresAt'] ?? null);

        return new NotificationEntry('Your verification ends soon', ($date ? "It ends on {$date}. " : '').'Re-verify to keep taking on new clients.', NotificationEntry::link('counsellor.dashboard', []));
    }
}
