<?php

namespace App\Notifications;

use App\Models\Request;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

// TT-4.11c/SCRUM-304: sent to the submitting user ($request->for) when an admin rejects their
// age-verification submission -- dob is left untouched, and per the user's own explicit decision
// there is no automated consequence for a rejected/misrepresented submission (moderation, if
// warranted, is a manual admin action, not automated here). Mirrors
// DobChangeRequestRejectedNotification's own shape.
class AgeVerificationRequestRejectedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(private Request $request)
    {
        $this->afterCommit();
    }

    /**
     * Get the notification's delivery channels.
     *
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

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->error()
            ->subject('Age Verification Not Approved')
            ->greeting("Hello {$notifiable->name}!")
            ->line('Your age-verification submission was not approved. Your account and access to therapy are unaffected -- this is entirely optional and only ever strengthens your record.')
            ->line("Thank you for choosing to 'TalkTherapy'.");
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'request_id' => $this->request->id,
        ];
    }
}
