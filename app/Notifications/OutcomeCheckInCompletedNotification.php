<?php

namespace App\Notifications;

use App\Contracts\ShownInNotificationCenter;
use App\Models\OutcomeCheckIn;
use App\Support\NotificationEntry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

// SCRUM-37: the counsellor's client answered a check-in -- nothing of the answers or the score.
class OutcomeCheckInCompletedNotification extends Notification implements ShouldQueue, ShownInNotificationCenter
{
    use Queueable;

    private const TITLE = 'A check-in was answered';

    private const TEXT = 'Your client answered a check-in you sent. You can see it on the therapy.';

    private int $therapyId;

    private int $checkInId;

    public function __construct(OutcomeCheckIn $checkIn)
    {
        $this->therapyId = (int) $checkIn->therapy_id;
        $this->checkInId = (int) $checkIn->id;
        $this->afterCommit();
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        $via = ['database'];

        if ($notifiable->email && $notifiable->email_verified_at) {
            $via[] = 'mail';
        }

        return $via;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(self::TITLE)
            ->greeting("Hello {$notifiable->name}!")
            ->line(self::TEXT)
            ->action('Open the therapy', route('therapies.get', ['therapyId' => $this->therapyId, 'checkIn' => $this->checkInId]))
            ->line("Thank you for choosing to 'TalkTherapy'.");
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return ['therapy_id' => $this->therapyId, 'check_in_id' => $this->checkInId];
    }

    public static function present(array $data): NotificationEntry
    {
        return new NotificationEntry(self::TITLE, self::TEXT, NotificationEntry::link('therapies.get', ['therapyId' => $data['therapy_id'] ?? null, 'checkIn' => $data['check_in_id'] ?? null]));
    }
}
