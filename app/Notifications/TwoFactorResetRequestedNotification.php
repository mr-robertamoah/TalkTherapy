<?php

namespace App\Notifications;

use App\Contracts\ShownInNotificationCenter;
use App\Models\TwoFactorReset;
use App\Support\NotificationEntry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;

// SCRUM-424: the team scheduled a reset of their two-factor sign-in -- when it applies, and how to stop it. The email
// (verified address only) carries a signed "cancel this reset" link, good until the reset applies; the in-app entry
// never holds that link (it's a bearer token) -- it points to the profile, where it can be cancelled too.
class TwoFactorResetRequestedNotification extends Notification implements ShouldQueue, ShownInNotificationCenter
{
    use Queueable;

    public function __construct(public string $resetId, public string $dueAt)
    {
        $this->afterCommit();
    }

    // Ops review: the reset may only apply once this email went out (AppServiceProvider: NotificationSent on mail).
    public static function markSent(object $event): void
    {
        if ($event->notification instanceof self && $event->channel === 'mail') {
            TwoFactorReset::query()->whereKey($event->notification->resetId)->whereNull('notified_at')->update(['notified_at' => now()]);
        }
    }

    public static function for(TwoFactorReset $reset): self
    {
        return new self($reset->id, $reset->due_at->toIso8601String());
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
        // In the app's time zone (QA: parsed from the ISO string it printed "GMT+0000").
        $due = Carbon::parse($this->dueAt)->setTimezone(config('app.timezone'));

        return (new MailMessage)
            ->subject('Two-factor sign-in reset scheduled')
            ->greeting("Hello {$notifiable->name}!")
            ->line('The TalkTherapy team has scheduled a reset of two-factor sign-in on your account, as someone asked us to.')
            ->line("It takes effect on {$due->format('j F Y, H:i T')}. After that your account is protected by its password alone until you set two-factor sign-in up again.")
            ->line('If you asked for this, you needn\'t do anything.')
            ->line('If you did NOT, cancel it now, then change your password and contact support -- someone may be trying to get into your account. Signing in with your two-factor code also cancels it.')
            // Readable for a week after it's due (code review): someone too late learns it applied and to contact support,
            // instead of a bare "invalid signature". The button only ever cancels a reset that's still waiting.
            ->action('Cancel this reset', URL::temporarySignedRoute('two_factor_reset.cancel.show', $due->copy()->addDays(7), ['reset' => $this->resetId]))
            ->line("Thank you for choosing to 'TalkTherapy'.");
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return ['dueAt' => $this->dueAt];
    }

    public static function present(array $data): NotificationEntry
    {
        $due = isset($data['dueAt']) ? Carbon::parse($data['dueAt'])->setTimezone(config('app.timezone'))->format('j F Y, H:i T') : 'soon';

        return new NotificationEntry(
            'Two-factor sign-in reset scheduled',
            "The team scheduled a reset of your two-factor sign-in for {$due}. Not you? Cancel it from your profile and change your password.",
            NotificationEntry::link('profile.show', []),
        );
    }
}
