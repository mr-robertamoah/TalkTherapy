<?php

namespace App\Notifications;

use App\Models\CounsellorPayout;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

// TT-7.6c/SCRUM-227: sent to both the counsellor and (separately, to 2 random admins, matching
// AppService::alertAdminsOfFailedJob's existing admin-notification convention) whenever a payout
// fails or reverses -- reassures the counsellor their balance was restored, not lost, which
// matters on a platform whose counsellors may already worry about income (product-owner note,
// SCRUM-224 review).
class PayoutFailedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private CounsellorPayout $payout, private ?string $reason = null)
    {
        //
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $amount = $this->payout->currency.' '.number_format($this->payout->amount / 100, 2);

        // This notification is sent to BOTH a Counsellor and a plain User (2 random admins).
        // Counsellor::getName() is a real method with its own "fall back to the linked user's
        // name if the counsellor's own name column is unset" logic -- User only has the ->name
        // accessor, no getName() method at all. Calling ->getName() unconditionally would
        // fatal-error rendering the admin copy in production (security review finding;
        // Notification::fake() in this file's own tests never actually calls toMail(), so the
        // bug was invisible to them). Falling back to ->name for a plain User is correct and
        // loses nothing Counsellor::getName()'s own fallback would have added.
        $recipientName = method_exists($notifiable, 'getName') ? $notifiable->getName() : $notifiable->name;

        $message = (new MailMessage)
            ->subject('A Counsellor Payout Failed')
            ->greeting("Hello {$recipientName}!")
            ->line("A payout of {$amount} for {$this->payout->counsellor->getName()} could not be completed.");

        if ($this->reason) {
            $message->line("Reason: {$this->reason}");
        }

        return $message
            ->line('The affected balance has been restored and is available for another payout attempt.')
            ->line('Thank you for choosing to "TalkTherapy".');
    }

    public function toArray(object $notifiable): array
    {
        return [
            //
        ];
    }
}
