<?php

namespace App\Notifications;

use App\Models\Refund;
use App\Notifications\Concerns\EscapesMarkdown;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

// TT-7.7d/SCRUM-252: sent to 2 random platform admins (mirrors PayoutFailedNotification's own
// admin-recipient half) when the real Paystack refund call fails after an admin already approved
// it. Deliberately admin-only, unlike PayoutFailedNotification's dual counsellor+admin recipients
// -- the client-facing "your refund failed" notification is TT-7.7e's own scope (it needs the
// "your platform/therapy access is unaffected" reassurance copy, which belongs with that
// ticket's other outcome-notification work, not duplicated ahead of it here).
//
// Security-engineer finding: every call site today only ever passes a static string or one of
// Paystack's own short status enum values ('failed'/'processed') as $reason -- never raw free
// text -- so there is no live injection issue yet. Still escaped defensively (EscapesMarkdown,
// TT-7.7c/SCRUM-251's own shared trait) since this choke point is explicitly meant to carry
// Paystack's own gateway response text, which this codebase does not control the contents of.
class RefundExecutionFailedNotification extends Notification implements ShouldQueue
{
    use EscapesMarkdown, Queueable;

    public function __construct(private Refund $refund, private ?string $reason = null)
    {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $amount = $this->refund->currency.' '.number_format($this->refund->amount / 100, 2);
        $transaction = $this->refund->transaction;

        $message = (new MailMessage)
            ->error()
            ->subject('A Refund Could Not Be Processed')
            ->greeting("Hello {$notifiable->name}!")
            ->line("A refund of {$amount} for transaction \"{$transaction->reference}\" could not be completed by Paystack.");

        if ($this->reason) {
            $message->line('Reason: '.$this->escapeMarkdown($this->reason));
        }

        return $message
            ->line('No automatic retry will happen -- please investigate and decide next steps manually.')
            ->line('Thank you for choosing to "TalkTherapy".');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'refundId' => $this->refund->id,
            'transactionId' => $this->refund->transaction_id,
        ];
    }
}
