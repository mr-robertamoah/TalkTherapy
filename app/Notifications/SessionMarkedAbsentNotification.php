<?php

namespace App\Notifications;

use App\Contracts\ShownInNotificationCenter;
use App\Models\Session;
use App\Support\MailText;
use App\Support\NotificationEntry;
use App\Support\TherapyLink;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

// SCRUM-446 (SCRUM-115): to a client or member when their counsellor marks them ABSENT from an in-person session (the
// first mark, or a change to ABSENT -- never PRESENT, never an unchanged mark saved again). Only ever to the person
// marked: an anonymous member is told about their own mark and nobody else is. It names the session and its date only
// -- never the place, the counsellor who marked it or anyone else on the register.
class SessionMarkedAbsentNotification extends Notification implements ShouldQueue, ShownInNotificationCenter
{
    use Queueable;

    public function __construct(public Session $session)
    {
        $this->afterCommit();
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return $notifiable->email_verified_at ? ['database', 'mail'] : ['database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('You were marked absent')
            ->greeting("Hello {$notifiable->name},")
            ->line(MailText::inline(self::message($this->session->name, $this->session->start_time?->toFormattedDateString(), $this->isPaid())))
            ->action('Open your therapy', TherapyLink::to($this->session->for));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'session_id' => $this->session->id,
            'sessionName' => $this->session->name,
            'sessionDate' => $this->session->start_time?->toIso8601String(),
            // Review: a refund is only mentioned for a paid session -- saved, so the bell says what the email said.
            'paid' => $this->isPaid(),
            ...($this->session->for ? TherapyLink::ids($this->session->for) : []),
        ];
    }

    // SCRUM-39: in the notification center -- from the saved data only, never more than the email.
    public static function present(array $data): NotificationEntry
    {
        return new NotificationEntry(
            'You were marked absent',
            self::message(NotificationEntry::text($data, 'sessionName', 'a session'), NotificationEntry::date($data['sessionDate'] ?? null), ($data['paid'] ?? false) === true),
            TherapyLink::fromIds($data),
        );
    }

    private function isPaid(): bool
    {
        return $this->session->payment_type === 'PAID';
    }

    private static function message(string $sessionName, ?string $date, bool $paid): string
    {
        $on = $date ? " on {$date}" : '';
        $refund = $paid ? ' or ask for a refund' : '';

        return "You were marked absent from {$sessionName}{$on}. If that's wrong, talk to your counsellor{$refund}.";
    }
}
