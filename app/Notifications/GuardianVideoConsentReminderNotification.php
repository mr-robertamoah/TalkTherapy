<?php

namespace App\Notifications;

use App\Models\Session;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

// TT-3.1e-e/SCRUM-284: sent to EVERY guardian of the ward (not just one) -- consistent with the
// "any one guardian can act, but all should know" theme GetVideoConsentAuditTrailForWardAction
// already established for the audit trail in e-b. $window is 'day_before' or 'hour_before',
// purely for copy -- both are independent, both can fire for the same session.
class GuardianVideoConsentReminderNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Session $session, public string $window)
    {
        $this->afterCommit();
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        $via = ['database', 'broadcast'];

        if ($notifiable->email_verified_at) {
            $via[] = 'mail';
        }

        return $via;
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        // Reviewer finding (2026-09-11): a session can be less than a day out by the time the
        // day-before sweep catches it (created/rescheduled with little notice) -- a hardcoded
        // "tomorrow" would be actively misleading in that case. diffForHumans() stays accurate
        // regardless of how close start_time actually is when this fires.
        $timing = $this->session->start_time->diffForHumans();

        return (new MailMessage)
            ->subject("Video Consent Needed for '{$this->session->name}'")
            ->greeting("Hello {$notifiable->name}!")
            ->line("The session '{$this->session->name}' for '{$this->session->for->name}' is starting {$timing}, and video consent has not yet been given.")
            ->line('Without your consent, the client will not be able to join the video call for this session.')
            ->action('Give Video Consent', url("therapies/{$this->session->for_id}"))
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
            'session_id' => $this->session->id,
            'window' => $this->window,
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage([
            'session' => [
                'id' => $this->session->id,
                'name' => $this->session->name,
            ],
            'window' => $this->window,
        ]);
    }

    public function broadcastType(): string
    {
        return 'video-consent.reminder';
    }
}
