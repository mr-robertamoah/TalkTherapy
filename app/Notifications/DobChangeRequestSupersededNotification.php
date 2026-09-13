<?php

namespace App\Notifications;

use App\Models\Request;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

// TT-4.11c/SCRUM-304: sent to whoever originally requested the dob change ($request->from) when
// it's auto-closed (RequestStatusEnum::superseded) because the same user's ageVerification
// request was approved instead -- so the requester isn't left with a silently-vanished request
// and no explanation, mirroring DobChangeRequestApprovedNotification/RejectedNotification's own
// shape.
class DobChangeRequestSupersededNotification extends Notification implements ShouldQueue
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
            ->subject('Date of Birth Change Request Closed')
            ->greeting("Hello {$notifiable->name}!")
            ->line('Your requested date-of-birth change is no longer needed: the date of birth has already been confirmed through a separate, admin-reviewed age verification.')
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
