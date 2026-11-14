<?php

namespace App\Notifications;

use App\Support\MaskedEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Notification as Notifications;

// SCRUM-422: to the address an account had before, when that address was verified -- sent straight to it (on demand),
// since the account itself now points at the new one, which may be someone else's. Mail only: an in-app entry would go
// to whoever holds the account now. The new address is only ever shown masked, and it never says "reset your password"
// (that link would go to the new address). A plain subject line: a reused mailbox needn't learn more than it must.
class EmailChangedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $name, public ?string $maskedNewEmail, public string $changedAt, public bool $byTeam = false)
    {
        $this->afterCommit();
    }

    // The one way to send it (the profile, SCRUM-422, and the admin user edit, SCRUM-425): nothing when the address before
    // wasn't verified. $name is the one the account had before the change.
    public static function sendTo(?string $previousEmail, string $name, ?string $newEmail, bool $byTeam = false): void
    {
        if (! $previousEmail) {
            return;
        }

        Notifications::route('mail', $previousEmail)->notify(new self(
            $name,
            $newEmail ? MaskedEmail::of($newEmail) : null,
            now()->format('j F Y, H:i T'),
            $byTeam,
        ));
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your account email was changed')
            ->greeting("Hello {$this->name}!")
            ->line(match (true) {
                ! $this->maskedNewEmail => "This email address was removed from your TalkTherapy account on {$this->changedAt}.",
                $this->byTeam => "The TalkTherapy team changed the email address on your account from this one to {$this->maskedNewEmail} on {$this->changedAt}.",
                default => "The email address on your TalkTherapy account was changed from this one to {$this->maskedNewEmail} on {$this->changedAt}.",
            })
            ->line('This address will no longer get emails about the account.')
            // SCRUM-425 (QA): a change the team made at their request shouldn't read as if they made it themselves.
            ->line($this->byTeam ? 'If you asked us to, no further action is needed.' : 'If you made this change, no further action is needed.')
            ->line('If you did NOT, someone else may have access to your account: contact TalkTherapy support from this address straight away.')
            ->line("Thank you for choosing to 'TalkTherapy'.");
    }
}
