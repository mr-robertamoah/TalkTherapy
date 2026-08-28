<?php

namespace App\Notifications;

use App\Enums\OrganizationCounsellorCompensationTypeEnum;
use App\Models\Organization;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

// SCRUM-146 (TT-6.4c): sent to the recipient (`to`) of a new compensation-change proposal or
// counter-offer. `$organization` is always the Organization the affiliation belongs to,
// regardless of which party actually proposed this specific round.
class OrganizationCounsellorCompensationChangeProposedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private Organization $organization, private array $terms)
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
            ->subject('New Compensation Terms Proposed')
            ->greeting("Hello {$notifiable->getName()}!")
            ->line("{$this->organization->name} has proposed new compensation terms for your affiliation.")
            ->line($this->describeTerms())
            ->line('Your current terms, if any, remain unchanged until you respond.')
            ->action('Go Home', url(''))
            ->line("Thank you for choosing to 'TalkTherapy'.");
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'organizationId' => $this->organization->id,
        ];
    }

    private function describeTerms(): string
    {
        return match ($this->terms['type'] ?? null) {
            OrganizationCounsellorCompensationTypeEnum::fixed->value => "Proposed terms: {$this->terms['amount']} {$this->terms['currency']}.",
            OrganizationCounsellorCompensationTypeEnum::percentage->value => "Proposed terms: {$this->terms['percentage']}% ({$this->terms['basis']}).",
            OrganizationCounsellorCompensationTypeEnum::free->value => 'Proposed terms: free (no compensation).',
            default => 'Proposed terms are available in the app.',
        };
    }
}
