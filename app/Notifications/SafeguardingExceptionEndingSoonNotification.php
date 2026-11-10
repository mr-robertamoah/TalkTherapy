<?php

namespace App\Notifications;

use App\Contracts\SentOnlyToSuperAdministrators;
use App\Contracts\ShownInNotificationCenter;
use App\Models\SafeguardingException;
use App\Support\NotificationEntry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

// SCRUM-381: to the super administrators, three days before a safeguarding exception runs out. No
// name and no reason by email -- those stay behind the admin page.
class SafeguardingExceptionEndingSoonNotification extends Notification implements SentOnlyToSuperAdministrators, ShouldQueue, ShownInNotificationCenter
{
    use Queueable;

    public function __construct(private SafeguardingException $exception)
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
            ->subject('A safeguarding exception ends soon')
            ->line('A safeguarding exception for a minor with no guardian ends on '.SafeguardingException::formatEnd($this->exception->ends_at).'. Their therapy pauses again then, unless a guardian is added or the exception is renewed.')
            ->action('Review safeguarding exceptions', route('administrator.safeguarding_exceptions'));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'safeguarding_exception.ending_soon',
            'safeguardingExceptionId' => $this->exception->id,
            'endsAt' => $this->exception->ends_at->toJSON(),
        ];
    }

    // SCRUM-39: in the notification center.
    public static function present(array $data): NotificationEntry
    {
        $date = NotificationEntry::date($data['endsAt'] ?? null);

        return new NotificationEntry('A safeguarding exception ends soon', $date ? "It ends on {$date}." : '', NotificationEntry::link('administrator.safeguarding_exceptions', []));
    }
}
