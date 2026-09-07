<?php

namespace App\Notifications;

use App\Models\OrganizationInvoice;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

// TT-7.3b-followup/SCRUM-245: sent to 2 random platform admins (mirrors PayoutFailedNotification's
// own AppService::alertAdminsOfFailedJob-style convention) when a previously-failed retainer
// invoice for a currently-suspended organization settles via RetryOrganizationInvoiceSettlementAction
// -- this does NOT itself lift the suspension (a settled invoice alone doesn't prove the org's
// broader payment standing is fixed, and auto-lifting without a human decision would blunt the
// whole point of suspending in the first place); it only tells staff the suspension may now be
// ready to lift via LiftOrganizationBillingSuspensionAction.
class OrganizationBillingSuspensionMayBeResolvedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private OrganizationInvoice $invoice)
    {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $organization = $this->invoice->organization;

        return (new MailMessage)
            ->subject('A Suspended Organization\'s Invoice Has Settled')
            ->greeting("Hello {$notifiable->name}!")
            ->line("\"{$organization->name}\" is currently billing-suspended, but its previously-failed invoice for the period starting {$this->invoice->period_start->toDateString()} has now settled.")
            ->line('This does not automatically lift the suspension -- review the organization\'s standing and lift it manually if appropriate.')
            ->action('Review Organization Billing', route('administrator.organization_billing'))
            ->line('Thank you for choosing to "TalkTherapy".');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'organizationId' => $this->invoice->organization_id,
            'organizationInvoiceId' => $this->invoice->id,
        ];
    }
}
