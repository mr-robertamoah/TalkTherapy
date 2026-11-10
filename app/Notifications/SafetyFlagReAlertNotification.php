<?php

namespace App\Notifications;

use App\Contracts\SentOnlyToSuperAdministrators;
use App\Contracts\ShownInNotificationCenter;
use App\Enums\SafetyFlagStatusEnum;
use App\Models\SafetyFlag;
use App\Support\NotificationEntry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

// SCRUM-32 (TT-4.3b): to the super administrators not involved -- a safety flag is still waiting for
// someone to acknowledge it. How urgent, how long, and a link: never who, why, where or which counsellor.
class SafetyFlagReAlertNotification extends Notification implements SentOnlyToSuperAdministrators, ShouldQueue, ShownInNotificationCenter
{
    use Queueable;

    public function __construct(private int $safetyFlagId, private string $publicId, private string $urgencyLabel, private int $hoursWaiting)
    {
        $this->afterCommit();
    }

    public function shouldSend(object $notifiable, string $channel): bool
    {
        return (bool) config('safety.enabled')
            && SafetyFlag::query()->whereKey($this->safetyFlagId)->where('status', SafetyFlagStatusEnum::open->value)->exists();
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
        $waited = $this->hoursWaiting < 1 ? 'under an hour' : ($this->hoursWaiting === 1 ? 'an hour' : "{$this->hoursWaiting} hours");

        return (new MailMessage)
            ->subject("Still waiting: safety alert ({$this->urgencyLabel})")
            ->greeting($notifiable instanceof AnonymousNotifiable ? 'Hello,' : "Hello {$notifiable->name},")
            ->line("A counsellor raised a safety flag ({$this->urgencyLabel}) {$waited} ago and no administrator has acknowledged it yet.")
            ->line('Please open the safety alerts and acknowledge it, or make sure someone does.')
            ->action('Open the safety alerts', route('administrator.safety_flags'));
    }

    public function toArray(object $notifiable): array
    {
        return ['type' => 'safety_flag.realert', 'safetyFlag' => $this->publicId, 'urgency' => $this->urgencyLabel];
    }

    // SCRUM-39: in the notification center.
    public static function present(array $data): NotificationEntry
    {
        return new NotificationEntry('Still waiting: safety alert'.SafetyFlagRaisedNotification::urgency($data), 'No administrator has acknowledged it yet.', NotificationEntry::link('administrator.safety_flags', []));
    }
}
