<?php

namespace App\Notifications;

use App\Models\Counsellor;
use App\Models\Request;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

// SCRUM-149 (TT-6.4c): sent once, ~2 days before a pending compensation-change request's
// expires_at, to whoever the request is currently addressed `to` -- a Counsellor or (once
// counter-offers exist) each admin of the Organization.
class OrganizationCounsellorCompensationChangeExpiryReminderNotification extends Notification implements ShouldQueue
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
            ->subject('Compensation Terms Expiring Soon')
            ->greeting("Hello {$this->notifiableName($notifiable)}!")
            ->line("A pending compensation-terms negotiation for your affiliation with {$organization->name} expires on {$this->request->expires_at->toFormattedDateString()}.")
            ->line('Respond, counter-offer, or the proposal will automatically expire unanswered.')
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
}
