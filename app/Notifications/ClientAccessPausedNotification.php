<?php

namespace App\Notifications;

use App\Models\Therapy;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

// SCRUM-288: to the counsellor of an individual therapy paused because its minor client has no
// active guardian. The client is named as that therapy shows them ($clientName from
// displayNameOf(), so an anonymous client stays anonymous). Nothing about the client's family.
class ClientAccessPausedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private Therapy $therapy, private string $clientName)
    {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return $notifiable->email_verified_at ? ['database', 'mail'] : ['database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('A therapy is paused')
            ->greeting("Hello {$notifiable->name},")
            ->line("Your therapy with {$this->clientName} is paused until their account has a guardian again.")
            ->line('You can still read its history. Messages, sessions and calls are on hold, and upcoming sessions stay booked; you can still cancel one.')
            ->action('Open the therapy', route('therapies.get', ['therapyId' => $this->therapy->id]))
            ->line('It starts again on its own. You will be told when it does.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'guardian_access.client_paused',
            'therapyId' => $this->therapy->id,
            'clientName' => $this->clientName,
        ];
    }
}
