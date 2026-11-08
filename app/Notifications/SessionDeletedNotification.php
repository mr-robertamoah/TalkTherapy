<?php

namespace App\Notifications;

use App\Contracts\ShownInNotificationCenter;
use App\Models\Counsellor;
use App\Models\Session;
use App\Support\NotificationEntry;
use App\Support\TherapyLink;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SessionDeletedNotification extends Notification implements ShouldQueue, ShownInNotificationCenter
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
        $name = $notifiable::class == Counsellor::class ? $notifiable->getName() : $notifiable->name;
        [$type, $url] = $this->session->getNotificationActionData();

        return (new MailMessage)
            ->subject("'{$this->session->name}' Session")
            ->greeting("Hello {$name}!")
            ->line("The session with name: '{$this->session->name}' for '{$this->session->for->name}' ".($this->session->for->isTherapy ? 'Therapy' : 'Group Therapy').' has been deleted.')
            ->action("Visit {$type} Page", $url)
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
            // SCRUM-39: the therapy or group, so the notification center can link it (old rows have none).
            ...($this->session->for ? TherapyLink::ids($this->session->for) : []),
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        // SCRUM-374: a small fixed summary -- the same for every recipient, never a per-viewer
        // resource (payment status, dob) built for whoever happened to be signed in.
        return new BroadcastMessage([
            'session' => [
                'id' => $this->session->id,
                'name' => $this->session->name,
                'startTime' => $this->session->start_time,
                'endTime' => $this->session->end_time,
                'status' => $this->session->status,
            ],
        ]);
    }

    public function broadcastType(): string
    {
        return 'session.deleted';
    }

    // SCRUM-39 (TT-5.4b): in the notification center -- from the saved ids only, never more than the email.
    public static function present(array $data): NotificationEntry
    {
        return new NotificationEntry('A session was deleted', '', TherapyLink::fromIds($data));
    }
}
