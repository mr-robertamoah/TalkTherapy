<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

// SCRUM-32 (TT-4.3b): the counsellor withdrew a safety flag. No content: the withdrawal reason is on the
// flag, for the handling administrator. Worded for who is told (reviews):
// - HANDLER: the administrator handling it;
// - ALERTED: nobody had acknowledged it -- the administrators who were alerted;
// - SUPERS: the handler can no longer act on it (they've since become involved) -- the super
//   administrators who aren't, to hand it on.
class SafetyFlagWithdrawnNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public const HANDLER = 'handler';

    public const ALERTED = 'alerted';

    public const SUPERS = 'supers';

    public function __construct(private string $publicId, private string $urgencyLabel, private string $audience)
    {
        $this->afterCommit();
    }

    public function shouldSend(object $notifiable, string $channel): bool
    {
        return (bool) config('safety.enabled');
    }

    public function via(object $notifiable): array
    {
        if ($notifiable instanceof AnonymousNotifiable) {
            return ['mail'];
        }

        return $notifiable->email_verified_at ? ['database', 'mail'] : ['database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Safety alert withdrawn ({$this->urgencyLabel})")
            ->greeting($notifiable instanceof AnonymousNotifiable ? 'Hello,' : "Hello {$notifiable->name},")
            ->line(match ($this->audience) {
                self::HANDLER => "The counsellor withdrew a safety flag ({$this->urgencyLabel}) you are handling. You can still add notes and resolve it.",
                self::SUPERS => "A counsellor withdrew a safety flag ({$this->urgencyLabel}) whose handling administrator can no longer act on it. Please hand it to someone who can resolve it.",
                default => "A counsellor withdrew a safety flag ({$this->urgencyLabel}) before anyone acknowledged it. Nothing more is needed unless you have concerns.",
            })
            ->action('Open the safety alerts', route('administrator.safety_flags'));
    }

    public function toArray(object $notifiable): array
    {
        return ['type' => 'safety_flag.withdrawn', 'safetyFlag' => $this->publicId, 'urgency' => $this->urgencyLabel];
    }
}
