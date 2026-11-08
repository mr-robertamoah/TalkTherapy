<?php

namespace App\Notifications;

use App\Contracts\ShownInNotificationCenter;
use App\Models\Therapy;
use App\Support\NotificationEntry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TherapyAssistanceRequestAcceptedGuardianNotification extends Notification implements ShouldQueue, ShownInNotificationCenter
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(private Therapy $therapy)
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
            ->subject('Assistance Request Accepted')
            ->greeting("Hello {$notifiable->name}!")
            ->line($this->therapy->anonymous
                ? "The request of an anonymous ward, for assistance for the therapy with name: '{$this->therapy->name}' has been accepted by counsellor with name: '{$this->therapy->counsellor->getName()}'."
                : "The request of your ward with name: '{$this->therapy->addedby->name}', for assistance for the therapy with name: '{$this->therapy->name}' has been accepted by counsellor with name: '{$this->therapy->counsellor->getName()}'.")
            ->action('Visit Counsellor Page', url("counsellors/{$this->therapy->counsellor_id}"))
            ->line('You may receive other reminders before the starting time.')
            ->action('Visit Therapy Page', url("therapies/{$this->therapy->id}"))
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
            'therapy_id' => $this->therapy->id,
        ];
    }

    // SCRUM-39 (TT-5.4b): in the notification center -- from the saved ids only, never more than the email.
    public static function present(array $data): NotificationEntry
    {
        return new NotificationEntry("Your ward's request for help was accepted", 'A counsellor will help with their therapy.', NotificationEntry::link('therapies.get', ['therapyId' => $data['therapy_id'] ?? null]));
    }
}
