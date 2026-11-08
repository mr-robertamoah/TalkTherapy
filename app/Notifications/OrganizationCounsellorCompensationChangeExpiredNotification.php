<?php

namespace App\Notifications;

use App\Contracts\ShownInNotificationCenter;
use App\Models\Counsellor;
use App\Models\Request;
use App\Support\NotificationEntry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

// SCRUM-149 (TT-6.4c): sent when a pending compensation-change request auto-expires unanswered,
// to whoever it was addressed `to` at the moment it expired.
class OrganizationCounsellorCompensationChangeExpiredNotification extends Notification implements ShouldQueue, ShownInNotificationCenter
{
    use Queueable;

    public function __construct(private Request $request)
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
        $organization = $this->request->for->organization;

        return (new MailMessage)
            ->error()
            ->subject('Compensation Terms Expired')
            ->greeting("Hello {$this->notifiableName($notifiable)}!")
            ->line("A pending compensation-terms negotiation for your affiliation with {$organization->name} expired without a response.")
            ->line('Any existing terms for this affiliation remain unchanged.')
            ->action('Go Home', url(''))
            ->line("Thank you for choosing to 'TalkTherapy'.");
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'organizationCounsellorId' => $this->request->for_id,
        ];
    }

    private function notifiableName(object $notifiable): string
    {
        return $notifiable instanceof Counsellor ? $notifiable->getName() : $notifiable->name;
    }

    // SCRUM-39 (TT-5.4b): in the notification center -- from the saved ids only, never more than the email.
    public static function present(array $data): NotificationEntry
    {
        return new NotificationEntry('A compensation negotiation expired', 'Any existing terms are unchanged.', NotificationEntry::link('organizations.mine.dashboard', []));
    }
}
