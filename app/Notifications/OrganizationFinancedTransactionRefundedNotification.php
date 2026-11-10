<?php

namespace App\Notifications;

use App\Contracts\ShownInNotificationCenter;
use App\Models\Transaction;
use App\Support\NotificationEntry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

// TT-7.3b-g/SCRUM-239: sent to every admin of the org that financed $transaction, once
// ReconcileOrgFinancedRefundAction has reconciled its earning(s) -- mirrors
// NotifyCompensationRequestRecipientAction's precedent of notifying every admin of an Organization
// recipient, not a single one.
class OrganizationFinancedTransactionRefundedNotification extends Notification implements ShouldQueue, ShownInNotificationCenter
{
    use Queueable;

    // $needsManualReview: true when at least one of the transaction's earnings was already
    // PAID_OUT, or already claimed by an in-flight payout (PROCESSING), by the time this
    // reconciliation ran -- neither can be safely auto-reversed (reviewer/security-engineer
    // finding: PROCESSING is claimed by a specific CounsellorPayout whose own later resolution
    // does a blanket, status-agnostic update that would silently clobber a reversal), so the org
    // needs to know manual follow-up is required, distinct from the ordinary case where reversal
    // fully covered it.
    public function __construct(private Transaction $transaction, private bool $needsManualReview)
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
        $mail = (new MailMessage)
            ->subject('Financed Session Refunded')
            ->greeting("Hello {$notifiable->name}!")
            ->line("A session/therapy your organization financed (reference {$this->transaction->reference}) has been refunded.");

        if ($this->needsManualReview) {
            $mail->error()->line('The counsellor\'s earning for this engagement has already been paid out, or a payout is already in progress for it, and could not be automatically reversed -- this needs manual follow-up.');
        } else {
            $mail->line('The counsellor\'s pending earning for this engagement has been reversed.');
        }

        return $mail->action('Go Home', url(''))->line("Thank you for choosing to 'TalkTherapy'.");
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'transactionId' => $this->transaction->id,
            'needsManualReview' => $this->needsManualReview,
        ];
    }

    // SCRUM-39 (TT-5.4b): in the notification center -- from the saved ids only, never more than the email.
    public static function present(array $data): NotificationEntry
    {
        return new NotificationEntry(
            'A session or therapy your organization financed was refunded',
            ! empty($data['needsManualReview']) ? "The counsellor's earning couldn't be reversed automatically and needs manual follow-up." : "The counsellor's pending earning was reversed.",
            NotificationEntry::link('organizations.mine.dashboard', []),
        );
    }
}
