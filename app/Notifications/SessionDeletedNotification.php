<?php

namespace App\Notifications;

use App\Http\Resources\SessionResource;
use App\Models\Counsellor;
use App\Models\Session;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SessionDeletedNotification extends Notification implements ShouldQueue
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

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $name = $notifiable::class == Counsellor::class ? $notifiable->getName() : $notifiable->name;
        [$type, $url] = $this->session->getNotificationActionData();

        return (new MailMessage)
            ->subject("'{$this->session->name}' Session")
            ->greeting("Hello {$name}!")
            ->line("The session with name: '{$this->session->name}' for '{$this->session->for->name}' " . ($this->session->for->isTherapy ? "Therapy" : "Group Therapy") . " has been deleted.")
            ->action("visit {$type} page", $url)
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
            'session_id' => $this->session->id,
        ];
    }
    
    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return (new BroadcastMessage([
            'session' => new SessionResource($this->session)
        ]));
    }

    public function broadcastType(object $notifiable): string
    {
        return 'session.deleted';
    }
}
