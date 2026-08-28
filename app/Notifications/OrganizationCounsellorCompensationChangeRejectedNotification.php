<?php

namespace App\Notifications;

use App\Models\Request;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

// SCRUM-147 (TT-6.4c): sent to whoever proposed the now-rejected terms. A flat decline -- the
// affiliation's status and existing compensation terms, if any, are always left untouched.
class OrganizationCounsellorCompensationChangeRejectedNotification extends Notification implements ShouldQueue
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
            ->subject('Compensation Terms Declined')
            ->greeting("Hello {$notifiable->name}!")
            ->line("Your proposed compensation terms for the affiliation with {$organization->name} were declined.")
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
}
