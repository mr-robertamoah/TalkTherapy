<?php

namespace App\Notifications;

use App\Contracts\ShownInNotificationCenter;
use App\Models\CounsellorRecognition;
use App\Models\Request;
use App\Support\NotificationEntry;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

// TT-7.11d1/SCRUM-473: to a counsellor a super administrator has recognised for a month, asking whether they are
// happy to be featured -- answered from the consent request on their dashboard ("needs your action"). Under the
// counsellors' "Recognition" notification kind (SCRUM-40), so it can be switched off by email or in the app; the
// request itself stays on their dashboard either way. Only the month is stored and shown -- never stars or rank.
class CounsellorRecognisedNotification extends Notification implements ShouldQueue, ShownInNotificationCenter
{
    use Queueable;

    public function __construct(private CounsellorRecognition $recognition, private Request $request)
    {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return $notifiable->email_verified_at ? ['database', 'mail'] : ['database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $title = self::title($this->recognition->monthLabel());

        return (new MailMessage)
            ->subject($title)
            ->greeting("Hello {$notifiable->getName()},")
            ->line("{$title}.")
            ->line('You can choose whether you are happy to be featured. Your answer is waiting on your dashboard.')
            ->action('Open your dashboard', route('counsellor.dashboard'));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'recognition.recognised',
            'recognitionId' => $this->recognition->id,
            'requestId' => $this->request->id,
            'month' => $this->recognition->monthStart()->format('Y-m'),
        ];
    }

    // SCRUM-39: in the notification center, from the saved month only.
    public static function present(array $data): NotificationEntry
    {
        $month = is_string($data['month'] ?? null) && preg_match('/^\d{4}-\d{2}$/', $data['month'])
            ? rescue(fn () => CarbonImmutable::createFromFormat('!Y-m', $data['month'], 'UTC')->format('F Y'), null, report: false)
            : null;

        return new NotificationEntry(
            $month ? self::title($month) : 'You\'ve been recognised as a Counsellor of the month',
            'Choose whether you are happy to be featured.',
            NotificationEntry::link('counsellor.dashboard', []),
        );
    }

    private static function title(string $month): string
    {
        return "You've been recognised as a Counsellor of the month for {$month}";
    }
}
