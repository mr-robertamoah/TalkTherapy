<?php

namespace App\Notifications;

use App\Http\Resources\SessionResource;
use App\Http\Resources\TherapyTopicMiniResource;
use App\Models\Session;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SessionTopicSetNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(private Session $session)
    {
        //
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['broadcast'];
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'topic' => new TherapyTopicMiniResource($this->session->currentTopic)
        ];
    }

    public function broadcastType(): string
    {
        return 'session.topic.set';
    }
}
