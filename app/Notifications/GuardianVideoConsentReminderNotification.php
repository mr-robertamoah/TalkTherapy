<?php

namespace App\Notifications;

use App\Contracts\ShownInNotificationCenter;
use App\Enums\VideoConsentReminderWindowEnum;
use App\Models\Session;
use App\Models\User;
use App\Support\NotificationEntry;
use App\Support\TherapyLink;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

// TT-3.1e-e/SCRUM-284: sent to EVERY guardian of the ward (not just one) -- consistent with the
// "any one guardian can act, but all should know" theme GetVideoConsentAuditTrailForWardAction
// already established for the audit trail in e-b. $window is 'day_before' or 'hour_before',
// purely for copy -- both are independent, both can fire for the same session.
class GuardianVideoConsentReminderNotification extends Notification implements ShouldQueue, ShownInNotificationCenter
{
    use Queueable;

    // SCRUM-313: $ward for a group session -- which of the guardian's wards it's about. A guardian of a group member
    // can't open the group, so a group reminder names it and links to their list of their ward's groups instead.
    // A plain property with a default, not a promoted one: a notification queued before it existed is unserialized
    // without it, and must read null rather than throw.
    public ?User $ward = null;

    public function __construct(public Session $session, public string $window, ?User $ward = null)
    {
        $this->ward = $ward;
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

        if ($this->ward) {
            return (new MailMessage)
                ->subject('Video Consent Needed for a Group Session')
                ->greeting("Hello {$notifiable->name}!")
                ->line("A session of the group therapy '{$this->session->for->name}' that {$this->ward->name} is in starts {$timing}, and video consent has not yet been given.")
                ->line('Without your consent, they will not be able to join its video call. You can give it, for the whole group or this session, from the Ward Section of your therapies page.')
                ->action('Give Video Consent', route('therapies'))
                ->line("Thank you for choosing to 'TalkTherapy'.");
        }

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
            // SCRUM-39: the therapy, so the notification center can link it (old rows have none). SCRUM-313: never a
            // group's -- its member's guardian can't open it; they answer from their ward list.
            ...($this->session->for && ! $this->ward ? TherapyLink::ids($this->session->for) : []),
            'window' => $this->window,
            // Their own ward's name, so two wards in one group read apart in the notification centre.
            ...($this->ward ? ['group' => true, 'wardName' => $this->ward->name] : []),
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        // SCRUM-313: nothing about a group's sessions to a guardian who can't open it.
        if ($this->ward) {
            return new BroadcastMessage(['window' => $this->window, 'group' => true]);
        }

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

    // SCRUM-39 (TT-5.4b): in the notification center -- from the saved ids only, never more than the email.
    public static function present(array $data): NotificationEntry
    {
        return new NotificationEntry('Video consent needed', (($data['window'] ?? null) === VideoConsentReminderWindowEnum::hour_before->value ? 'A session starting within the hour' : 'An upcoming session')
            .' needs your video consent, or '.(! empty($data['group']) ? NotificationEntry::text($data, 'wardName', 'your ward') : 'the client').' cannot join its video call.',
            ! empty($data['group']) ? NotificationEntry::link('therapies', []) : TherapyLink::fromIds($data));
    }
}
