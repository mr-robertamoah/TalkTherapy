<?php

namespace App\Actions\Organization;

use App\Actions\Action;
use App\Enums\OrganizationAdminRoleEnum;
use App\Enums\OrganizationInvoiceStatusEnum;
use App\Enums\TransactionStatusEnum;
use App\Enums\TransactionStatusSourceEnum;
use App\Jobs\ProcessOrganizationInvoiceSettlementJob;
use App\Models\Organization;
use App\Models\OrganizationInvoice;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

// TT-7.3b-e/SCRUM-236: claims an `open` invoice for settlement and starts the real Paystack
// charge, mirroring TriggerCounsellorPayoutAction/ProcessCounsellorPayoutJob's own split -- lock,
// verify still claimable, and record the claim inside one DB::transaction() here; the actual
// gateway call happens in a queued job dispatched AFTER that transaction commits, so one org's
// slow/erroring settlement can never block or crash the periodic sweep's other invoices.
//
// This is NOT built on top of ChargeOrganizationForModelAction (architect decision, SCRUM-230
// review): that action's cost computation assumes a single counsellor/listed amount, which has no
// meaning for a whole settled period potentially covering many counsellors -- this action only
// mirrors its charge-and-record TAIL shape (Transaction creation, status history, a
// TransactionStatusSourceEnum case of its own).
class SettleOrganizationInvoiceAction extends Action
{
    public function execute(OrganizationInvoice $invoice): ?Transaction
    {
        $transaction = DB::transaction(function () use ($invoice) {
            $locked = OrganizationInvoice::query()->whereKey($invoice->id)->lockForUpdate()->first();

            if (! $locked || ! $locked->isOpen()) {
                return null;
            }

            // Security-engineer finding: without this, nothing stops a caller (today only the
            // periodic sweep, but a future admin manual-settle action -- TT-7.3b-j -- could call
            // this directly) from claiming a STILL-ACCRUING invoice mid-period. RecordOrganizationInvoiceLineForSessionAction's
            // own invoice lookup is keyed on the CURRENT calendar month at write time and does not
            // filter by status, so a line held after this invoice was prematurely claimed would
            // silently attach to an invoice whose `amount` was already fixed -- generating a real
            // CounsellorEarning for money that was never actually charged. Requiring the period to
            // have fully closed first makes that structurally impossible: once period_end has
            // passed, no future `held` session can ever compute this same period_start again.
            //
            // Mirrors AppService::settleDueOrganizationInvoices()'s own `< today` boundary exactly
            // -- period_end is a bare date (the whole day it names still belongs to the period),
            // so a plain isFuture() check on a date read back at midnight would already read as
            // "past" partway through period_end's own day, wrongly allowing same-day settlement.
            if ($locked->period_end->greaterThanOrEqualTo(today())) {
                Log::warning('Cannot settle an organization invoice -- its period has not closed yet.', [
                    'organization_invoice_id' => $locked->id,
                    'organization_id' => $locked->organization_id,
                    'period_end' => $locked->period_end->toDateString(),
                ]);

                return null;
            }

            $lines = $locked->lines()->get();

            if ($lines->isEmpty()) {
                return null;
            }

            $instrument = $locked->organization->paymentInstrument;

            if (! $instrument) {
                Log::warning('Cannot settle an organization invoice -- this organization has no payment instrument on file.', [
                    'organization_invoice_id' => $locked->id,
                    'organization_id' => $locked->organization_id,
                ]);

                return null;
            }

            // Security-engineer finding: an org with retainer counsellors listed in more than one
            // currency can have its single payment instrument registered in a currency that
            // doesn't match every invoice it settles (invoices are deliberately per-currency --
            // see the organization_invoices migration comment). Checked explicitly rather than
            // left to Paystack to reject, matching this method's other precondition guards.
            if (strtoupper($instrument->currency) !== strtoupper($locked->currency)) {
                Log::warning('Cannot settle an organization invoice -- the organization\'s payment instrument currency does not match the invoice currency.', [
                    'organization_invoice_id' => $locked->id,
                    'organization_id' => $locked->organization_id,
                    'invoice_currency' => $locked->currency,
                    'instrument_currency' => $instrument->currency,
                ]);

                return null;
            }

            $billingUser = $this->resolveBillingUser($locked->organization);

            if (! $billingUser) {
                Log::warning('Cannot settle an organization invoice -- this organization has no admin to attribute the settlement to.', [
                    'organization_invoice_id' => $locked->id,
                    'organization_id' => $locked->organization_id,
                ]);

                return null;
            }

            $amount = (int) $lines->sum(fn ($line) => $line->net_amount + $line->fee_amount);

            $transaction = Transaction::query()->create([
                'for_type' => OrganizationInvoice::class,
                'for_id' => $locked->id,
                'user_id' => $billingUser->id,
                'organization_id' => $locked->organization_id,
                'reference' => 'org_settlement_'.Str::uuid(),
                'amount' => $amount,
                'currency' => $locked->currency,
                'status' => TransactionStatusEnum::pending->value,
            ]);

            $transaction->statusHistories()->create([
                'status' => TransactionStatusEnum::pending->value,
                'source' => TransactionStatusSourceEnum::orgSettlement->value,
                'message' => 'Retainer invoice settlement initiated.',
            ]);

            $locked->update([
                'status' => OrganizationInvoiceStatusEnum::pending->value,
                'amount' => $amount,
            ]);

            return $transaction;
        });

        // Dispatched AFTER the transaction commits, not from inside it -- same reasoning as
        // TriggerCounsellorPayoutAction's own identical comment: this queue connection's
        // `after_commit` config is false, so a job dispatched inside the transaction could be
        // picked up by a worker before the invoice/transaction rows are actually committed.
        if ($transaction) {
            ProcessOrganizationInvoiceSettlementJob::dispatch($transaction->id);
        }

        return $transaction;
    }

    // No acting user initiates a periodic settlement sweep (unlike, e.g., a member-initiated
    // payment-instrument registration) -- an owner-role admin is the closest analogue to "who
    // this organization's billing belongs to", falling back to any admin if no owner somehow
    // exists (EnsureOrganizationRetainsAnOwnerAction should make that impossible, but this action
    // must never crash on it).
    private function resolveBillingUser(Organization $organization): ?User
    {
        return $organization->admins()->wherePivot('role', OrganizationAdminRoleEnum::owner->value)->first()
            ?? $organization->admins()->first();
    }
}
