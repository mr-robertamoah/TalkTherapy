<?php

namespace App\Notifications;

use App\Contracts\SentOnlyToAdministrators;
use App\Contracts\ShownInNotificationCenter;
use App\Models\Counsellor;
use App\Support\NotificationEntry;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

// SCRUM-31: to the administrator who last accepted a counsellor's verification, with the counsellor's
// own reminder (the user's decision: the people involved in verifying them hear it's due). Every
// administrator also sees it in the to-do count and the renewals list.
class CounsellorVerificationEndingAdminNotification extends Notification implements SentOnlyToAdministrators, ShouldQueue, ShownInNotificationCenter
{
    use Queueable;

    public function __construct(private Counsellor $counsellor, private CarbonInterface $expiresAt, private int $daysLeft)
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
            ->subject('A counsellor\'s verification ends soon')
            ->greeting("Hello {$notifiable->name},")
            ->line("{$this->counsellor->getName()}'s verification, which you accepted, ends on {$this->expiresAt->toFormattedDateString()}, in {$this->daysLeft} ".($this->daysLeft === 1 ? 'day' : 'days').'.')
            ->line('They have been asked to re-verify. Their request will appear in the admin area to review.')
            ->action('Open renewals', route('administrator.counsellor_verification', ['view' => 'renewals']));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'counsellor_verification.ending_admin',
            'counsellorId' => $this->counsellor->id,
            'expiresAt' => $this->expiresAt->toJSON(),
            'daysLeft' => $this->daysLeft,
        ];
    }

    // SCRUM-39: in the notification center.
    public static function present(array $data): NotificationEntry
    {
        $date = NotificationEntry::date($data['expiresAt'] ?? null);

        return new NotificationEntry("A counsellor's verification ends soon", ($date ? "A verification you accepted ends on {$date}. " : '').'They have been asked to re-verify.', NotificationEntry::link('administrator.counsellor_verification', ['view' => 'renewals']));
    }
}
