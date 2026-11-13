<?php

namespace App\Notifications;

use App\Contracts\ShownInNotificationCenter;
use App\Models\GroupTherapy;
use App\Models\User;
use App\Support\NotificationEntry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

// SCRUM-313: to each of a minor's guardians when the minor asks for consent to take part in a group's video. Names the
// ward (to their own guardian) and the group, never its other members; links to the guardian's list of their ward's
// groups, where consent is given -- a guardian of a member can't open the group itself.
class GroupVideoConsentRequestedNotification extends Notification implements ShouldQueue, ShownInNotificationCenter
{
    use Queueable;

    public function __construct(public GroupTherapy $groupTherapy, public User $ward)
    {
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
        return (new MailMessage)
            ->subject('Video Consent Requested')
            ->greeting("Hello {$notifiable->name}!")
            ->line("{$this->ward->name} has asked for your consent to take part in video calls in the group therapy '{$this->groupTherapy->name}'.")
            ->line('You can give it for the whole group or for one session, and withdraw it at any time, from the Ward Section of your therapies page.')
            ->action('Review Video Consent', route('therapies'))
            ->line("Thank you for choosing to 'TalkTherapy'.");
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'groupTherapyId' => $this->groupTherapy->id,
            'wardId' => $this->ward->id,
        ];
    }

    // SCRUM-39 (TT-5.4b): in the notification center -- from the saved ids only, never more than the email.
    public static function present(array $data): NotificationEntry
    {
        return new NotificationEntry('Video consent requested', 'Your ward asked for your consent to take part in a group therapy\'s video calls.', NotificationEntry::link('therapies', []));
    }
}
