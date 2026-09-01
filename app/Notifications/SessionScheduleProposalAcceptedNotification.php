<?php

namespace App\Notifications;

use App\Models\Request;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

// SCRUM-207 (TT-2.5b): sent to whoever proposed the now-accepted time -- not necessarily the
// client, since a counter-offer round can flip who proposed last.
class SessionScheduleProposalAcceptedNotification extends Notification implements ShouldQueue
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
        $therapy = $this->request->for;

        return (new MailMessage)
            ->success()
            ->subject('Session Time Accepted')
            ->greeting("Hello {$notifiable->name}!")
            ->line("Your proposed session time for therapy: {$therapy->name} has been accepted.")
            ->action('Go Home', url(''))
            ->line("Thank you for choosing to 'TalkTherapy'.");
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'therapyId' => $this->request->for_id,
            'requestId' => $this->request->id,
        ];
    }
}
