<?php

namespace App\Notifications;

use App\Models\Refund;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

// TT-7.7e/SCRUM-253: sent to the client once RecordRefundStatusAction records the refund as
// SUCCESS -- the "outcome" step TT-7.7d's own approve-time notification (accept has none) and
// TT-7.7c's reject-time RefundRequestRejectedNotification were both deliberately left for. Must
// explicitly state platform/therapy access is unaffected (product-owner-mandated, mental-health
// trust requirement -- a client must never wonder if a refund means they've lost access to their
// counsellor), mirroring RefundRequestRejectedNotification's own identical line.
class RefundSucceededNotification extends Notification implements ShouldQueue
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
        $amount = $this->refund->currency.' '.number_format($this->refund->amount / 100, 2);
        $transaction = $this->refund->transaction;

        return (new MailMessage)
            ->subject('Your Refund Has Been Processed')
            ->greeting("Hello {$notifiable->name}!")
            ->line("Your refund of {$amount} for transaction \"{$transaction->reference}\" has been processed.")
            ->line('Your access to the platform and your therapy is completely unaffected by this refund.')
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
