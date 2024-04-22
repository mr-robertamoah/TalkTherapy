<?php

namespace App\Notifications;

use App\Models\Visitor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class VisitorsStatusNotification extends Notification implements ShouldQueue
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
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $time = now()->toTimeString();
        $day = now()->toFormattedDayDateString();

        $visitors = Visitor::count();
        $userVisitors = Visitor::query()->whereUser()->count();
        $nonUserVisitors = Visitor::query()->whereNonUser()->count();

        return (new MailMessage)
            ->subject('App Status')
            ->greeting("Hello {$notifiable->name}!")
            ->line("This is to alert you of the current status of the application's use as at {$time} of {$day}.")
            ->line("{$visitors} number of visitations.")
            ->line("{$userVisitors} number of user visitations.")
            ->line("{$nonUserVisitors} number of non-user visitations.")
            ->line('Thank you!');
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
