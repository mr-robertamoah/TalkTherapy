<?php

namespace App\Notifications;

use App\Contracts\ShownInNotificationCenter;
use App\Support\NotificationEntry;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

// SCRUM-425 (security review): the team changed the email on someone's account -- told in the app as well, so an
// account without a verified address (no email could reach them) still hears of it while they're the one signed in.
// The new address only masked.
class AccountEmailChangedByTeamNotification extends Notification implements ShownInNotificationCenter
{
    use Queueable;

    public function __construct(public ?string $maskedNewEmail)
    {
        $this->afterCommit();
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return ['maskedNewEmail' => $this->maskedNewEmail];
    }

    public static function present(array $data): NotificationEntry
    {
        $to = $data['maskedNewEmail'] ?? null;

        return new NotificationEntry(
            'Your account email was changed',
            ($to ? "The TalkTherapy team changed your account's email to {$to}." : 'The TalkTherapy team removed the email from your account.').' Not something you asked for? Contact support now.',
            NotificationEntry::link('profile.show', []),
        );
    }
}
