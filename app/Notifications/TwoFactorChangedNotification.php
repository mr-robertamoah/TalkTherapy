<?php

namespace App\Notifications;

use App\Contracts\ShownInNotificationCenter;
use App\Support\NotificationEntry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

// SCRUM-34: a security notice when two-factor sign-in is turned on or off, new recovery codes are made, or a recovery
// code is used to sign in -- so someone who got into the account and changed it can't do so unseen. Never carries a
// secret or a code.
class TwoFactorChangedNotification extends Notification implements ShouldQueue, ShownInNotificationCenter
{
    use Queueable;

    public const ENABLED = 'enabled';

    public const DISABLED = 'disabled';

    public const RECOVERY_CODES_REGENERATED = 'recovery_codes_regenerated';

    // SCRUM-34b: signing in with a recovery code.
    public const RECOVERY_CODE_USED = 'recovery_code_used';

    private const TEXT = [
        self::ENABLED => ['Two-factor sign-in turned on', 'Two-factor sign-in was turned on for your account.'],
        self::DISABLED => ['Two-factor sign-in turned off', 'Two-factor sign-in was turned off for your account.'],
        self::RECOVERY_CODES_REGENERATED => ['New recovery codes made', 'New two-factor recovery codes were made for your account. The old ones no longer work.'],
        self::RECOVERY_CODE_USED => ['Signed in with a recovery code', 'Someone signed in to your account with one of your two-factor recovery codes. That code no longer works.'],
    ];

    public function __construct(public string $change)
    {
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
        [$title, $message] = self::TEXT[$this->change] ?? self::TEXT[self::ENABLED];

        return (new MailMessage)
            ->subject($title)
            ->greeting("Hello {$notifiable->name}!")
            ->line($message)
            ->line('If you did this, no further action is needed.')
            ->line('If you did NOT, change your password now and contact support -- someone else may have access to your account.')
            ->action('Open your profile', route('profile.show'))
            ->line("Thank you for choosing to 'TalkTherapy'.");
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return ['change' => $this->change];
    }

    public static function present(array $data): NotificationEntry
    {
        [$title, $message] = self::TEXT[$data['change'] ?? null] ?? ['Two-factor sign-in changed', 'A two-factor sign-in setting changed on your account.'];

        return new NotificationEntry($title, $message.' Not you? Change your password now.', NotificationEntry::link('profile.show', []));
    }
}
