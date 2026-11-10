<?php

namespace App\Notifications;

use App\Contracts\ShownInNotificationCenter;
use App\Enums\SuspensionScopeEnum;
use App\Models\Suspension;
use App\Support\NotificationEntry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

// SCRUM-359: a suspension is over -- it reached its end, or an administrator lifted it.
// SCRUM-382: $therapyStaysPaused when the person is still a minor without an active guardian, so it
// doesn't promise their therapy carries on (it stays paused, SCRUM-288).
class SuspensionEndedNotification extends Notification implements ShouldQueue, ShownInNotificationCenter
{
    use Queueable;

    public const EXPIRED = 'expired';

    public const LIFTED = 'lifted';

    public function __construct(private Suspension $suspension, private string $how, private bool $therapyStaysPaused = false)
    {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return $notifiable->email_verified_at ? ['database', 'mail'] : ['database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $what = $this->suspension->scope == SuspensionScopeEnum::account ? 'your account' : 'your counsellor role';

        return (new MailMessage)
            ->subject('Your TalkTherapy suspension has ended')
            ->greeting("Hello {$notifiable->name},")
            ->line($this->how == self::LIFTED
                ? "An administrator has lifted the suspension of {$what}."
                : "The suspension of {$what} has come to an end.")
            ->line($this->therapyStaysPaused
                ? 'You can sign in and use TalkTherapy again. Your therapy stays paused until a guardian is added to your account. Sessions cancelled during the suspension are not restored.'
                : 'You can use TalkTherapy as before. Sessions cancelled during the suspension are not restored.')
            ->action('Go to TalkTherapy', route('home'));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'suspension.ended',
            'suspensionId' => $this->suspension->id,
            'how' => $this->how,
            ...($this->therapyStaysPaused ? ['therapyPaused' => true] : []),
        ];
    }

    // SCRUM-39: in the notification center.
    public static function present(array $data): NotificationEntry
    {
        return new NotificationEntry('Your suspension has ended');
    }
}
