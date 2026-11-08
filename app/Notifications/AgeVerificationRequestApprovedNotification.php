<?php

namespace App\Notifications;

use App\Contracts\ShownInNotificationCenter;
use App\Models\Request;
use App\Support\NotificationEntry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

// TT-4.11c/SCRUM-304: sent to the submitting user ($request->for, always the same as $request->from
// for this type) once an admin approves their age-verification submission and the dob has been
// marked verified. Mirrors DobChangeRequestApprovedNotification's own shape.
class AgeVerificationRequestApprovedNotification extends Notification implements ShouldQueue, ShownInNotificationCenter
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
            ->success()
            ->subject('Age Verification Approved')
            ->greeting("Hello {$notifiable->name}!")
            ->line('Your age-verification submission has been reviewed and approved. Your date of birth is now marked as verified.')
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

    // SCRUM-39 (TT-5.4b): in the notification center -- from the saved ids only, never more than the email.
    public static function present(array $data): NotificationEntry
    {
        return new NotificationEntry('Your age verification was approved', 'Your date of birth is now marked as verified.', NotificationEntry::link('profile.show', []));
    }
}
