<?php

namespace App\Notifications;

use App\Models\Request;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

// TT-4.10d/SCRUM-293: sent to whoever originally requested the dob change ($request->from) once
// a guardian/admin approves it and the new dob has been applied.
class DobChangeRequestApprovedNotification extends Notification implements ShouldQueue
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
        $newDob = $this->request->data['newDob'] ?? null;

        return (new MailMessage)
            ->success()
            ->subject('Date of Birth Change Approved')
            ->greeting("Hello {$notifiable->name}!")
            ->line($newDob
                ? "Your requested date-of-birth change to {$newDob} has been approved and applied."
                : 'Your requested date-of-birth change has been approved and applied.')
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
