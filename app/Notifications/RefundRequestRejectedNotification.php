<?php

namespace App\Notifications;

use App\Contracts\ShownInNotificationCenter;
use App\Models\Request;
use App\Support\NotificationEntry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

// TT-7.7c/SCRUM-251: sent to the client immediately on reject -- a reject is this request's own
// final word (no further "outcome" step is coming later the way accept has, via TT-7.7d/e), so
// unlike accept it can't wait for a later notification to explain what happened.
class RefundRequestRejectedNotification extends Notification implements ShouldQueue, ShownInNotificationCenter
{
    use Queueable;

    public function __construct(private Request $request)
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
        $transaction = $this->request->for;
        $reason = $this->request->data['rejectionReason'] ?? null;

        $mail = (new MailMessage)
            ->error()
            ->subject('Your Refund Request Was Declined')
            ->greeting("Hello {$notifiable->name}!")
            ->line("Your refund request for transaction \"{$transaction->reference}\" has been declined.");

        if ($reason) {
            // SCRUM-254: escaped app-wide (Markdown::withSecuredEncoding in AppServiceProvider).
            $mail->line('Reason given: '.$reason);
        }

        return $mail
            ->line('Your access to the platform and your therapy is completely unaffected by this decision.')
            ->action('Go Home', url(''))
            ->line('Thank you for choosing to "TalkTherapy".');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'requestId' => $this->request->id,
            'transactionId' => $this->request->for_id,
        ];
    }

    // SCRUM-39 (TT-5.4b): in the notification center -- from the saved ids only, never more than the email.
    public static function present(array $data): NotificationEntry
    {
        return new NotificationEntry('Your refund request was declined', 'Your access to the platform and your therapy is unaffected.');
    }
}
