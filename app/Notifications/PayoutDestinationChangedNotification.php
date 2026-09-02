<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

// TT-7.6a/SCRUM-225: account-takeover mitigation -- sent whenever an EXISTING payout destination
// is replaced (not on first-time onboarding, since there's nothing suspicious about a counsellor
// setting up payout for the first time). Deliberately never includes the new account details
// themselves, so this email can't leak them if it's ever intercepted/misdelivered.
class PayoutDestinationChangedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your Payout Destination Was Changed')
            ->greeting("Hello {$notifiable->getName()}!")
            ->line('The bank account or mobile money number your TalkTherapy payouts are sent to was just changed.')
            ->line('If you made this change, no further action is needed.')
            ->line('If you did NOT make this change, please contact support immediately -- someone else may have access to your account.')
            ->line('Thank you for choosing to "TalkTherapy".');
    }

    public function toArray(object $notifiable): array
    {
        return [
            //
        ];
    }
}
