<?php

namespace App\Notifications;

use App\Contracts\ShownInNotificationCenter;
use App\Support\NotificationEntry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

// SCRUM-455 (Google sign-in, SCRUM-112): a security notice when a Google account is connected to, or disconnected from,
// the account -- a new way in must never be added unseen. Never names the Google account (its id or email).
class GoogleSignInChangedNotification extends Notification implements ShouldQueue, ShownInNotificationCenter
{
    use Queueable;

    public const CONNECTED = 'connected';

    public const DISCONNECTED = 'disconnected';

    // Security review: changing the password alone doesn't remove a Google account someone else connected.
    public const NOT_YOU = [
        self::CONNECTED => 'If you did NOT, disconnect Google from your profile AND change your password -- changing the password alone doesn\'t remove Google sign-in. Then contact support.',
        self::DISCONNECTED => 'If you did NOT, change your password now and contact support -- someone else may have access to your account.',
    ];

    private const TEXT = [
        self::CONNECTED => ['Google sign-in connected', 'A Google account was connected to your TalkTherapy account. It can now be used to sign in.'],
        self::DISCONNECTED => ['Google sign-in disconnected', 'Google sign-in was disconnected from your TalkTherapy account. Sign in with your username or email and password.'],
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
        [$title, $message] = self::TEXT[$this->change] ?? self::TEXT[self::CONNECTED];

        return (new MailMessage)
            ->subject($title)
            ->greeting("Hello {$notifiable->name}!")
            ->line($message)
            ->line('If you did this, no further action is needed.')
            ->line(self::NOT_YOU[$this->change] ?? self::NOT_YOU[self::DISCONNECTED])
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
        [$title, $message] = self::TEXT[$data['change'] ?? null] ?? ['Google sign-in changed', 'Google sign-in changed on your account.'];
        $notYou = ($data['change'] ?? null) === self::CONNECTED
            ? ' Not you? Disconnect Google from your profile and change your password -- the password alone doesn\'t remove Google sign-in.'
            : ' Not you? Change your password now.';

        return new NotificationEntry($title, $message.$notYou, NotificationEntry::link('profile.show', []));
    }
}
