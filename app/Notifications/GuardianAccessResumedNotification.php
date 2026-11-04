<?php

namespace App\Notifications;

use App\Models\Therapy;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

// SCRUM-288: a paused minor has an active guardian again. Without a therapy it is to the minor; with
// one it is to the counsellor of that individual therapy, naming the client as the therapy shows them
// (displayNameOf(), masking-safe).
class GuardianAccessResumedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private ?Therapy $therapy = null, private ?string $clientName = null)
    {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return $notifiable->email_verified_at ? ['database', 'mail'] : ['database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->greeting("Hello {$notifiable->name},");

        if ($this->therapy) {
            return $mail
                ->subject('A paused therapy has started again')
                ->line("Your therapy with {$this->clientName} is no longer paused.")
                ->action('Open the therapy', route('therapies.get', ['therapyId' => $this->therapy->id]));
        }

        return $mail
            ->subject('Your therapy on TalkTherapy has started again')
            ->line('Your account has a guardian again, so your therapy is no longer paused. You can send messages, book sessions and join calls as before.')
            ->action('Go to TalkTherapy', route('home'));
    }

    public function toArray(object $notifiable): array
    {
        return $this->therapy
            ? ['type' => 'guardian_access.client_resumed', 'therapyId' => $this->therapy->id, 'clientName' => $this->clientName]
            : ['type' => 'guardian_access.resumed'];
    }
}
