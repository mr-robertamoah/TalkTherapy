<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

// Deliberately NOT ShouldQueue: queuing this would let its own delivery failure re-trigger
// Queue::failing() and cascade into another one of itself (SCRUM-82). Sent synchronously
// instead, from within AppService::alertAdminsOfFailedJob()'s own try/catch.
class QueueJobFailedNotification extends Notification
{
    public function __construct(
        private string $jobName,
        private string $connection,
        private string $queue,
        private string $exceptionMessage,
    ) {
        //
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->error()
            ->subject('Queue Job Failed')
            ->greeting("Hello {$notifiable->name}!")
            ->line('A queued job has failed after exhausting its retries.')
            ->line("Job: {$this->jobName}")
            ->line("Connection: {$this->connection}, Queue: {$this->queue}")
            ->line("Error: {$this->exceptionMessage}")
            ->line('Please check the application logs and the failed_jobs table for full details.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [];
    }
}
