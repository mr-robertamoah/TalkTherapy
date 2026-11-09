<?php

namespace App\Notifications;

use App\Contracts\ShownInNotificationCenter;
use App\Models\Request;
use App\Models\User;
use App\Support\NotificationEntry;
use App\Support\WardRef;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

// SCRUM-207 (TT-2.5b): sent to whoever proposed the now-rejected time. Covers both a bare reject
// and a reject-with-reason (Option C, e.g. "please propose a new time") -- the reason, if any, is
// included when present.
class SessionScheduleProposalRejectedNotification extends Notification implements ShouldQueue, ShownInNotificationCenter
{
    use Queueable;

    private ?string $wardRef;

    // SCRUM-396: $rejectedBy -- who wrote the reason, so anonymising their closed account clears it here.
    public function __construct(private Request $request, ?User $rejectedBy = null)
    {
        $this->wardRef = $rejectedBy && filled($request->data['reason'] ?? null) ? WardRef::of($rejectedBy) : null;
        $this->afterCommit();
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        $via = ['database'];

        if ($notifiable->email_verified_at) {
            $via[] = 'mail';
        }

        return $via;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $therapy = $this->request->for;
        $reason = $this->request->data['reason'] ?? null;

        $mail = (new MailMessage)
            ->error()
            ->subject('Session Time Declined')
            ->greeting("Hello {$notifiable->name}!")
            ->line("Your proposed session time for therapy: {$therapy->name} was declined.");

        if ($reason) {
            $mail->line($reason);
        }

        return $mail
            ->action('Go Home', url(''))
            ->line("Thank you for choosing to 'TalkTherapy'.");
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'therapyId' => $this->request->for_id,
            'requestId' => $this->request->id,
            'reason' => $this->request->data['reason'] ?? null,
            ...($this->wardRef ? [WardRef::KEY => $this->wardRef] : []),
        ];
    }

    // SCRUM-39 (TT-5.4b): in the notification center -- from the saved ids only, never more than the email.
    public static function present(array $data): NotificationEntry
    {
        return new NotificationEntry('Your proposed session time was declined', Str::limit(NotificationEntry::text($data, 'reason', ''), 300), NotificationEntry::link('therapies.get', ['therapyId' => $data['therapyId'] ?? null]));
    }
}
