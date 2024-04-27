<?php

namespace App\Notifications;

use App\Models\Counsellor;
use App\Models\Report;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ReportNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(private Report $report)
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
        $type = str_replace("App\Models\\", "", $this->report->reportable_type);
        if ($this->report->addedby_type == Counsellor::class) {
            $reporterName = $this->report->addedby->getName();
            $reporterType = 'counsellor';
        } else {
            $reporterName = $this->report->addedby->name;
            $reporterType = 'user';
        }

        return (new MailMessage)
            ->subject('Report Submitted')
            ->greeting("Hello {$notifiable->name}!")
            ->line("A report has been submitted. Please do have a look at it.")
            ->line("The report is about a {$type}.")
            ->line("It was submitted by {$reporterType} with name {$reporterName}.")
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
