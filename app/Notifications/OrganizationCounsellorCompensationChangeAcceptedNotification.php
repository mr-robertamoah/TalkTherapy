<?php

namespace App\Notifications;

use App\Enums\OrganizationCounsellorCompensationTypeEnum;
use App\Models\Request;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

// SCRUM-147 (TT-6.4c): sent to whoever proposed the now-accepted terms -- not necessarily the
// current org admin, since a future counter-offer round can flip who proposed last.
class OrganizationCounsellorCompensationChangeAcceptedNotification extends Notification implements ShouldQueue
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
            ->success()
            ->subject('Compensation Terms Accepted')
            ->greeting("Hello {$notifiable->name}!")
            ->line("Your proposed compensation terms for the affiliation with {$organization->name} have been accepted.")
            ->line($this->describeTerms())
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

    private function describeTerms(): string
    {
        $terms = $this->request->data;

        return match ($terms['type'] ?? null) {
            OrganizationCounsellorCompensationTypeEnum::fixed->value => "Accepted terms: {$terms['amount']} {$terms['currency']}.",
            OrganizationCounsellorCompensationTypeEnum::percentage->value => "Accepted terms: {$terms['percentage']}% ({$terms['basis']}).",
            OrganizationCounsellorCompensationTypeEnum::free->value => 'Accepted terms: free (no compensation).',
            default => 'Accepted terms are available in the app.',
        };
    }
}
