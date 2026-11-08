<?php

namespace App\Notifications;

use App\Contracts\ShownInNotificationCenter;
use App\Support\NotificationEntry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

// SCRUM-33 (TT-4.4c): to the person who asked for urgent help -- the counsellor they spoke with invites
// them to continue in a regular therapy. Nothing from the chat itself.
class HelpTherapyInviteNotification extends Notification implements ShouldQueue, ShownInNotificationCenter
{
    use Queueable;

    // $byCounsellor: the counsellor sent it themselves; otherwise our team suggested it (security review:
    // never tell someone a counsellor wants to continue when they didn't say so).
    public function __construct(private int $helpRequestId, private string $counsellorName, private bool $byCounsellor = true)
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
            ->subject($this->byCounsellor ? 'An invitation to continue with a counsellor' : 'A suggestion to continue with a counsellor')
            ->greeting("Hello {$notifiable->name},")
            ->line($this->byCounsellor
                ? "{$this->counsellorName}, the counsellor you spoke with when you asked for urgent help, invites you to continue with them in a regular therapy."
                : "Our team suggests you could continue in a regular therapy with {$this->counsellorName}, the counsellor you spoke with when you asked for urgent help.")
            ->line('It\'s up to you. Your urgent help chat isn\'t copied into it.')
            ->action('See the invitation', route('help.chat', ['helpRequest' => $this->helpRequestId]));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'help_request.therapy_invite',
            'helpRequestId' => $this->helpRequestId,
            'counsellorName' => $this->counsellorName,
            'byCounsellor' => $this->byCounsellor,
        ];
    }

    // SCRUM-39: in the notification center.
    public static function present(array $data): NotificationEntry
    {
        $name = NotificationEntry::text($data, 'counsellorName', 'A counsellor');

        return new NotificationEntry(
            ! empty($data['byCounsellor']) ? 'An invitation to continue with a counsellor' : 'A suggestion to continue with a counsellor',
            "To continue with {$name} in a regular therapy. It's up to you.",
            NotificationEntry::link('help.chat', ['helpRequest' => $data['helpRequestId'] ?? null]),
        );
    }
}
