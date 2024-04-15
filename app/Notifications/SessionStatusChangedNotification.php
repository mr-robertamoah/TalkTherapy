<?php

namespace App\Notifications;

use App\Enums\SessionStatusEnum;
use App\Http\Resources\SessionResource;
use App\Models\Session;
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
        $via = ['database', 'broadcast'];

        if ($notifiable->email_verified_at)
            $via[] = 'mail';
        
        return $via;
    }
    
    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return (new BroadcastMessage([
            'session' => new SessionResource($this->session)
        ]));
    }

    public function broadcastType(object $notifiable): string
    {
        return 'session.changed';
    }

    private function getSessionText()
    {
        return match ($this->session->status) {
            SessionStatusEnum::abandoned->value => "has been abondoned. A counsellor or user has ended the session before its end time.",
            SessionStatusEnum::in_session->value => "has started.",
            SessionStatusEnum::held->value => "has ended.",
            SessionStatusEnum::failed->value => "has did not take place.",
            SessionStatusEnum::held_confirmation->value => "requires the other person (Counsellor/User) to confirm that the in-person session has ended.",
            SessionStatusEnum::in_session_confirmation->value => "requires the other person (Counsellor/User) to confirm that the in-person session has started.",
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
