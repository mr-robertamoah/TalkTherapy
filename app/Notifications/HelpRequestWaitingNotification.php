<?php

namespace App\Notifications;

use App\Contracts\SentOnlyToAdministrators;
use App\Contracts\ShownInNotificationCenter;
use App\Enums\HelpRequestStatusEnum;
use App\Models\HelpRequest;
use App\Support\NotificationEntry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

// SCRUM-33 (TT-4.4b): to every administrator not involved -- someone has asked for urgent help and no
// counsellor has answered yet. Nothing about who: no name, no note; the queue shows the rest.
class HelpRequestWaitingNotification extends Notification implements SentOnlyToAdministrators, ShouldQueue, ShownInNotificationCenter
{
    use Queueable;

    public function __construct(private int $helpRequestId, private int $minutesWaiting, private bool $again)
    {
        $this->afterCommit();
    }

    // Queued: if the worker was down, it may go out late -- only while the person is still waiting.
    public function shouldSend(object $notifiable, string $channel): bool
    {
        return HelpRequest::query()->whereKey($this->helpRequestId)->where('status', HelpRequestStatusEnum::waiting->value)->exists();
    }

    public function via(object $notifiable): array
    {
        return $notifiable->email_verified_at ? ['database', 'mail'] : ['database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $waited = $this->minutesWaiting <= 1 ? 'about a minute' : "{$this->minutesWaiting} minutes";

        return (new MailMessage)
            ->subject($this->again ? 'Still waiting: someone needs urgent help' : 'Someone needs urgent help')
            ->greeting("Hello {$notifiable->name},")
            ->line("Someone asked for urgent help {$waited} ago and no counsellor has answered yet.")
            ->line('You can ring the counsellors on call again, or ring a counsellor directly.')
            ->action('Open the urgent help queue', route('administrator.help_requests'));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'help_request.waiting',
            'helpRequestId' => $this->helpRequestId,
            'again' => $this->again,
        ];
    }

    // SCRUM-39: in the notification center.
    public static function present(array $data): NotificationEntry
    {
        return new NotificationEntry(
            ! empty($data['again']) ? 'Still waiting: someone needs urgent help' : 'Someone needs urgent help',
            'No counsellor has answered yet.',
            NotificationEntry::link('administrator.help_requests', []),
        );
    }
}
