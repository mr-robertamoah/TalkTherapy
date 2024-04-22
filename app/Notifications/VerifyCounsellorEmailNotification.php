<?php

namespace App\Notifications;

use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

class VerifyCounsellorEmailNotification extends Notification
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct()
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
        return ['mail', 'broadcast'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $url = URL::temporarySignedRoute(
            'counsellor.verification.verify',
            Carbon::now()->addMinutes(120),
            [
                'counsellorId' => $notifiable->id,
                'hash' => sha1($notifiable->email),
            ]
        );
        $name = $notifiable->getName();

        return (new MailMessage)
            ->greeting("Hello {$name}!")
            ->subject('Verify Email Address')
            ->line('Please click the button below to verify your email address. Without verification, you cannot receive emails from this application.')
            ->action('Verify Email Address', $url)
            ->line('Note that the verification email link expires in about 2 hours time.')
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

    public function broadcastType(): string
    {
        return 'verify.email';
    }
}
