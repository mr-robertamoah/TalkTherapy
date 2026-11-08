<?php

namespace App\Notifications;

use App\Contracts\SentOnlyToAdministrators;
use App\Contracts\ShownInNotificationCenter;
use App\Enums\SafetyFlagStatusEnum;
use App\Models\SafetyFlag;
use App\Support\NotificationEntry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

// SCRUM-32: to every administrator not involved -- a counsellor raised a safety flag. How urgent, and
// a link: never who, why, which therapy or which counsellor (the handling administrator sees those).
class SafetyFlagRaisedNotification extends Notification implements SentOnlyToAdministrators, ShouldQueue, ShownInNotificationCenter
{
    use Queueable;

    public function __construct(private int $safetyFlagId, private string $publicId, private string $urgencyLabel)
    {
        $this->afterCommit();
    }

    // Queued: by the time it goes out someone may have picked it up (or it was withdrawn).
    public function shouldSend(object $notifiable, string $channel): bool
    {
        // And never once the feature has been switched off: the link would lead nowhere (devops review).
        return (bool) config('safety.enabled')
            && SafetyFlag::query()->whereKey($this->safetyFlagId)->where('status', SafetyFlagStatusEnum::open->value)->exists();
    }

    public function via(object $notifiable): array
    {
        // The fallback mailbox (TT-4.3b) is an address, not an account.
        if ($notifiable instanceof AnonymousNotifiable) {
            return ['mail'];
        }

        return $notifiable->email_verified_at ? ['database', 'mail'] : ['database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Safety alert ({$this->urgencyLabel}): a counsellor raised a safety flag")
            ->greeting($notifiable instanceof AnonymousNotifiable ? 'Hello,' : "Hello {$notifiable->name},")
            ->line("A counsellor raised a safety flag ({$this->urgencyLabel}): they think a client may need specialist or inpatient care.")
            ->line('Please open the safety alerts and acknowledge it if you can handle it. Only the administrator handling it sees who it is about.')
            ->action('Open the safety alerts', route('administrator.safety_flags'));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'safety_flag.raised',
            // The public id, never the sequential one (security review).
            'safetyFlag' => $this->publicId,
            'urgency' => $this->urgencyLabel,
        ];
    }

    // SCRUM-39: in the notification center.
    public static function present(array $data): NotificationEntry
    {
        return new NotificationEntry('Safety alert'.self::urgency($data), 'A counsellor raised a safety flag. Please acknowledge it if you can handle it.', NotificationEntry::link('administrator.safety_flags', []));
    }

    // " (urgent)" -- the urgency label saved with the alert, if any.
    public static function urgency(array $data): string
    {
        return is_string($data['urgency'] ?? null) && $data['urgency'] !== '' ? " ({$data['urgency']})" : '';
    }
}
