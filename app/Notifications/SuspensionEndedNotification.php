<?php

namespace App\Notifications;

use App\Enums\SuspensionScopeEnum;
use App\Models\Suspension;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

// SCRUM-359: a suspension is over -- it reached its end, or an administrator lifted it.
class SuspensionEndedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public const EXPIRED = 'expired';

    public const LIFTED = 'lifted';

    public function __construct(private Suspension $suspension, private string $how)
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
            ->line('You can use TalkTherapy as before. Sessions cancelled during the suspension are not restored.')
            ->action('Go to TalkTherapy', route('home'));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'suspension.ended',
            'suspensionId' => $this->suspension->id,
            'how' => $this->how,
        ];
    }
}
