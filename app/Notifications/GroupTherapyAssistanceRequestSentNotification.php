<?php

namespace App\Notifications;

use App\Models\Counsellor;
use App\Models\Request;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class GroupTherapyAssistanceRequestSentNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(private Request $request)
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
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        // SCRUM-55: decided by who SENT the request, not by who receives it. A counsellor sender is
        // named; a user sender never is -- they may be anonymous in this therapy, and the email
        // goes to a counsellor who hasn't been accepted yet.
        $fromCounsellor = $this->request->from instanceof Counsellor ? $this->request->from : null;
        $name = $notifiable instanceof Counsellor ? $notifiable->getName() : $notifiable->name;
        $type = $this->request->for->isTherapy ? 'therapy' : 'group therapy';

        return (new MailMessage)
            ->success()
            ->subject('Assistance Request Sent')
            ->greeting("Hello {$name}!")
            ->when(! $fromCounsellor, function ($mail) use ($type) {
                $mail
                    ->line("A user has sent you a request for assistance with {$type} with name: '{$this->request->for->name}'. Check your requests to accept or decline.");
            })
            ->when((bool) $fromCounsellor, function ($mail) use ($type, $fromCounsellor) {
                $mail
                    ->line("A counsellor with name: '{$fromCounsellor->getName()}' has sent you a request to assist you with {$type} with name: '{$this->request->for->name}'. Check your requests to accept or decline.");
            })
            ->when($this->request->for->isTherapy, function ($mail) {
                $mail
                    ->action('Visit Therapy Page', url("therapies/{$this->request->for->id}"));
            })
            ->when(! $this->request->for->isTherapy, function ($mail) {
                $mail
                    ->action('Visit Group Therapy Page', url("group-therapies/{$this->request->for->id}"));
            })
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
            //
        ];
    }
}
