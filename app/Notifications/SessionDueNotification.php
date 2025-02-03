<?php

namespace App\Notifications;

use App\Http\Resources\SessionResource;
use App\Models\Counsellor;
use App\Models\Session;
use App\Models\Therapy;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Throwable;

class SessionDueNotification extends Notification implements ShouldQueue
{
    use Queueable;

    private array $notificationActionData = [];
    /**
     * Create a new notification instance.
     */
    public function __construct(public Session $session)
    {
        $this->afterCommit();
        $this->notificationActionData = $this->session->getNotificationActionData();
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
        
        return (new MailMessage)
            ->success()
            ->subject("'{$this->session->name}' Session")
            ->greeting("Hello {$name}!")
            ->line("The session with name: '{$this->session->name}' which was created for " . "'{$this->session->for->name}' " . $this->notificationActionData[0] . " is about to start.")
            ->line("This session will be starting in less than 30 minutes. Please do not disappoint. Be on time.")
            ->action("Visit {$this->notificationActionData[0]} Page now", $this->notificationActionData[1])
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
        $item = $this->session->for_type == Therapy::class ? 'Therapy' : 'Group Therapy';

        return (new BroadcastMessage([
            'forName' => $this->session->for->name,
            'forId' => $this->session->for->id,
            'forType' => $item,
            'session' => [
                'id' => $this->session->id,
                'name' => $this->session->name,
            ]
        ]));
    }

    public function broadcastType(): string
    {
        return 'session.due';
    }
}
