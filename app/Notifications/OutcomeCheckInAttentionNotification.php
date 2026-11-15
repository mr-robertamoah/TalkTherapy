<?php

namespace App\Notifications;

use App\Contracts\ShownInNotificationCenter;
use App\Models\OutcomeCheckIn;
use App\Support\HelpPush;
use App\Support\NotificationEntry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

// SCRUM-37: a check-in answer needs the counsellor's attention (a self-harm answer, PHQ-9 item 9). Always sent (it
// can't be switched off -- SCRUM-40) and never says what the answer was: only that one needs them, and where.
class OutcomeCheckInAttentionNotification extends Notification implements ShouldQueue, ShownInNotificationCenter
{
    use Queueable;

    private const TITLE = 'A check-in answer needs your attention';

    private const TEXT = 'One of your client\'s check-in answers needs your attention. Please look at it as soon as you can and decide how to follow up. They have been shown how to get urgent help.';

    private int $therapyId;

    private int $checkInId;

    public function __construct(OutcomeCheckIn $checkIn)
    {
        $this->therapyId = (int) $checkIn->therapy_id;
        $this->checkInId = (int) $checkIn->id;
        $this->afterCommit();
    }

    // On the urgent queue (devops review), ahead of everyday notices -- as urgent help's pushes are (HelpPush::QUEUE).
    public function viaQueues(): array
    {
        return ['mail' => HelpPush::QUEUE, 'database' => HelpPush::QUEUE];
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
            ->action('Look at it now', route('therapies.get', ['therapyId' => $this->therapyId, 'checkIn' => $this->checkInId]))
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
