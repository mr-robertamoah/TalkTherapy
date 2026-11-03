<?php

namespace App\Notifications;

use App\Models\Counsellor;
use App\Models\Session;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SessionCreatedNotification extends Notification implements ShouldQueue
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
        $start = new Carbon($this->session->start_time);
        $startTime = $start->toDayDateTimeString();
        $duration = $start->diffInMinutes(new Carbon($this->session->end_time));
        $name = $notifiable::class == Counsellor::class ? $notifiable->getName() : $notifiable->name;
        [$type, $url] = $this->session->getNotificationActionData();

        return (new MailMessage)
            ->success()
            ->subject("'{$this->session->name}' Session")
            ->greeting("Hello {$name}!")
            ->line("A session with name: '{$this->session->name}' has been created for '{$this->session->for->name}' ".($this->session->for->isTherapy ? 'Therapy.' : 'Group Therapy.'))
            ->line("This session will start on {$startTime}. The session will last {$duration} minutes. Please take notice.")
            ->action("Visit {$type} Page", $url)
            ->line('You may receive other reminders before the starting time.')
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
        return 'session.created';
    }
}
