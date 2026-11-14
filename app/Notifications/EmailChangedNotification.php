<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

// SCRUM-422: to the address an account had before, when that address was verified -- sent straight to it (on demand),
// since the account itself now points at the new one, which may be someone else's. Mail only: an in-app entry would go
// to whoever holds the account now. The new address is only ever shown masked, and it never says "reset your password"
// (that link would go to the new address). A plain subject line: a reused mailbox needn't learn more than it must.
class EmailChangedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $name, public ?string $maskedNewEmail, public string $changedAt)
    {
        $this->afterCommit();
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
            ->line($this->maskedNewEmail
                ? "The email address on your TalkTherapy account was changed from this one to {$this->maskedNewEmail} on {$this->changedAt}."
                : "This email address was removed from your TalkTherapy account on {$this->changedAt}.")
            ->line('This address will no longer get emails about the account.')
            ->line('If you made this change, no further action is needed.')
            ->line('If you did NOT, someone else may have access to your account: contact TalkTherapy support from this address straight away.')
            ->line("Thank you for choosing to 'TalkTherapy'.");
    }
}
