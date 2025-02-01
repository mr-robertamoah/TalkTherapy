<?php

namespace App\Notifications;

use App\Enums\SessionStatusEnum;
use App\Http\Resources\SessionResource;
use App\Models\Counsellor;
use App\Models\Session;
use App\Models\Therapy;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SessionStatusChangedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(public Session $session)
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
        $via = ['broadcast'];

        if ($notifiable->email_verified_at)
            $via[] = 'mail';
        
        return $via;
    }
    
    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        $item = $this->session->for_type == Therapy::class ? 'Therapy' : 'Group Therapy';

        return (new BroadcastMessage([
            'forId' => $this->session->for_id,
            'forType' => $item,
            'sessionId' => $this->session->id,
            'status' => $this->session->status,
            'message' => "Visit {$item} with name: '{$this->session->for->name}' now. This session {$this->getSessionText()}"
        ]));
    }

    public function broadcastType(): string
    {
        return 'session.status';
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        [$type, $url] = $this->session->getNotificationActionData();
        $name = $notifiable::class == Counsellor::class ? $notifiable->getName() : $notifiable->name;
        
        return (new MailMessage)
            ->success()
            ->subject("'{$this->session->name}' Session")
            ->greeting("Hello {$name}!")
            ->line("The status for the session with name: '{$this->session->name}' changed.")
            ->line("This session {$this->getSessionText()}")
            ->action("Visit {$type} Page now", $url)
            ->line("Thank you for choosing to 'TalkTherapy'.");
    }

    private function getSessionText()
    {
        return match ($this->session->status) {
            SessionStatusEnum::abandoned->value => "has been abandoned. A counsellor or user has ended the session before its end time.",
            SessionStatusEnum::in_session->value => "has started.",
            SessionStatusEnum::held->value => "has ended.",
            SessionStatusEnum::failed->value => "has been failed. Meaning it did not take place.",
            SessionStatusEnum::held_confirmation->value => "requires you to confirm that the session has ended. Please visit page and click the 'end session' button.",
            SessionStatusEnum::in_session_confirmation->value => "requires you to confirm that the session has started. Please visit page and click the 'start session' button.",
            default => "is currently pending.",
        };
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'session_id' => $this->session->id
        ];
    }
}
