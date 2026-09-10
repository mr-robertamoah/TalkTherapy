<?php

namespace App\Notifications;

use App\Models\Request as ModelsRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

// TT-7.7b/SCRUM-250: sent to 2 random platform admins (mirrors
// OrganizationBillingSuspensionMayBeResolvedNotification/PayoutFailedNotification's own
// AppService::alertAdminsOfFailedJob-style convention) when a client asks for a refund -- refund
// requests have no single targeted `to` (any admin may respond), so there is no one specific
// recipient the way a compensation-change proposal has.
class RefundRequestedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private ModelsRequest $request)
    {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $transaction = $this->request->for;

        return (new MailMessage)
            ->subject('A Client Has Requested a Refund')
            ->greeting("Hello {$notifiable->name}!")
            ->line("A client has requested a refund for transaction \"{$transaction->reference}\" ({$transaction->currency} ".number_format($transaction->amount / 100, 2).').')
            ->line("Reason given: \"{$this->request->data['reason']}\"")
            // No dedicated refund-review queue page exists yet (TT-7.7c) -- links to the general
            // admin dashboard rather than a route that doesn't exist.
            ->action('Go to Admin Dashboard', route('administrator'))
            ->line('Thank you for choosing to "TalkTherapy".');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'requestId' => $this->request->id,
            'transactionId' => $this->request->for_id,
        ];
    }
}
