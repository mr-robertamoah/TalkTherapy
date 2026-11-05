<?php

namespace App\Notifications;

use App\Models\Request;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

// SCRUM-381: to the super administrators, when a counsellor asks for a safeguarding exception for a
// paused client. No name and no reason by email -- those stay behind the admin page.
class SafeguardingExceptionRequestedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private Request $request)
    {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return $notifiable->email_verified_at ? ['database', 'mail'] : ['database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('A counsellor has asked for a safeguarding exception')
            ->line('A counsellor has asked for a safeguarding exception for a client whose therapy is paused because they have no guardian.')
            ->action('Review the request', route('administrator.safeguarding_exceptions'));
    }

    public function toArray(object $notifiable): array
    {
        return ['type' => 'safeguarding_exception.requested', 'requestId' => $this->request->id];
    }
}
