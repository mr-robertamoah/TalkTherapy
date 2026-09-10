<?php

namespace App\Notifications;

use App\Models\Refund;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

// TT-7.7e/SCRUM-253: the client-facing counterpart to RefundExecutionFailedNotification (which is
// admin-only, and stays that way). Deliberately generic -- no gateway-response text is
// interpolated here, since that's internal/operational detail for admins to investigate, not
// something a client needs (or should have to parse) to understand what happened next. Must
// explicitly state platform/therapy access is unaffected, mirroring
// RefundRequestRejectedNotification/RefundSucceededNotification's own identical line.
class RefundFailedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private Refund $refund)
    {
        $this->afterCommit();
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        $via = ['database'];

        if ($notifiable->email_verified_at) {
            $via[] = 'mail';
        }

        return $via;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $transaction = $this->refund->transaction;

        return (new MailMessage)
            ->error()
            ->subject('We Could Not Process Your Refund')
            ->greeting("Hello {$notifiable->name}!")
            ->line("We were unable to complete your refund for transaction \"{$transaction->reference}\". Our team has been notified and will follow up with you directly.")
            ->line('Your access to the platform and your therapy is completely unaffected.')
            ->action('Go Home', url(''))
            ->line('Thank you for choosing to "TalkTherapy".');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'refundId' => $this->refund->id,
            'transactionId' => $this->refund->transaction_id,
        ];
    }
}
