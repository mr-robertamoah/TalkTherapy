<?php

namespace App\Notifications;

use App\Contracts\ShownInNotificationCenter;
use App\Models\PlatformFeeWaiver;
use App\Support\NotificationEntry;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

// TT-7.11e/SCRUM-475: to a counsellor a super administrator has given their own commission-free period. Under the
// counsellors' "Recognition" notification kind (SCRUM-40), like CounsellorRecognisedNotification. Only the period's
// start and end are stored and shown -- never the reason or which administrator granted it. Sent after commit.
class CounsellorFeeWaiverGrantedNotification extends Notification implements ShouldQueue, ShownInNotificationCenter
{
    use Queueable;

    public const TITLE = 'No platform commission on your payments for a while';

    public function __construct(private PlatformFeeWaiver $waiver)
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
            ->subject(self::TITLE)
            ->greeting("Hello {$notifiable->getName()},")
            ->line(self::message($this->waiver->starts_at?->toIso8601ZuluString(), $this->waiver->ends_at?->toIso8601ZuluString()).'.')
            ->line('You will see this on your dashboard and your payouts while it applies.')
            ->action('Open your dashboard', route('counsellor.dashboard'));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'fee_waiver.granted',
            'startsAt' => $this->waiver->starts_at?->toIso8601ZuluString(),
            'endsAt' => $this->waiver->ends_at?->toIso8601ZuluString(),
        ];
    }

    // SCRUM-39: in the notification center, from the saved dates only.
    public static function present(array $data): NotificationEntry
    {
        return new NotificationEntry(
            self::TITLE,
            self::message($data['startsAt'] ?? null, $data['endsAt'] ?? null).'.',
            NotificationEntry::link('counsellor.dashboard', []),
        );
    }

    // Worded as the on-page notice (resources/js/Support/commissionFree.js): UTC, labelled GMT.
    private static function message(mixed $startsAt, mixed $endsAt): string
    {
        $start = self::utc($startsAt);
        $end = self::utc($endsAt);

        return match (true) {
            $start !== null && $end !== null => "No platform commission on your payments between {$start} and {$end}",
            $start !== null => "No platform commission on your payments from {$start}",
            default => 'No platform commission on your payments for a period',
        };
    }

    private static function utc(mixed $value): ?string
    {
        return is_string($value) && $value !== ''
            ? rescue(fn () => CarbonImmutable::parse($value)->utc()->format('j M Y, H:i').' GMT', null, report: false)
            : null;
    }
}
