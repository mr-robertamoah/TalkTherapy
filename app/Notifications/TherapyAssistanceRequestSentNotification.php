<?php

namespace App\Notifications;

use App\Models\Counsellor;
use App\Models\Request;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TherapyAssistanceRequestSentNotification extends Notification implements ShouldQueue
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
        $isCounsellor = false;
        if ($notifiable::class == Counsellor::class) $isCounsellor = true;

        $name = $isCounsellor ? $notifiable->getName() : $notifiable->name;
        $fromName = $isCounsellor ? $this->request->from->name : $this->request->from->getName();
        $type = $this->request->for->isTherapy ? 'therapy' : 'group therapy';

        return (new MailMessage)
                ->success()
                ->subject("Assistance Request Sent")
                ->greeting("Hello {$name}!")
                ->when($isCounsellor, function ($mail) use ($type) {
                    $mail
                        ->line("A user has sent you a request for assistance him/her with {$type} with name: '{$this->request->for->name}'. Check your requests to accept or decline.");
                })
                ->when(!$isCounsellor, function ($mail) use ($type, $fromName) {
                    $mail
                        ->line("A counsellor with name: '{$fromName}' has sent you a request to assist you with {$type} with name: '{$this->request->for->name}'. Check your requests to accept or decline.");
                })
                ->when($this->request->for->isTherapy, function ($mail) {
                    $mail
                        ->action("Visit Therapy Page", url("therapies/{$this->request->for->id}"));
                })
                ->when(!$this->request->for->isTherapy, function ($mail) {
                    $mail
                        ->action("Visit Group Therapy Page", url("grouptherapies/{$this->request->for->id}"));
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
