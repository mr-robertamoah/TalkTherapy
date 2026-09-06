<?php

use App\Enums\OrganizationInvoiceStatusEnum;
use App\Enums\TransactionStatusEnum;
use App\Exceptions\TransactionException;
use App\Jobs\ProcessOrganizationInvoiceSettlementJob;
use App\Models\Organization;
use App\Models\OrganizationInvoice;
use App\Models\OrganizationPaymentInstrument;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

// TT-7.3b-e/SCRUM-236: the real Paystack chargeAuthorization() call for a claimed settlement,
// dispatched after SettleOrganizationInvoiceAction's own DB transaction commits -- mirrors
// ProcessCounsellorPayoutJobTest's own coverage shape for the payout job.

function aPendingSettlementTransaction(): array
{
    $organization = Organization::factory()->create();
    $instrument = OrganizationPaymentInstrument::factory()->create(['organization_id' => $organization->id, 'currency' => 'GHS']);
    $invoice = OrganizationInvoice::factory()->create([
        'organization_id' => $organization->id,
        'status' => OrganizationInvoiceStatusEnum::pending->value,
        'amount' => 7300,
        'currency' => 'GHS',
    ]);
    $transaction = Transaction::factory()->create([
        'for_type' => OrganizationInvoice::class,
        'for_id' => $invoice->id,
        'organization_id' => $organization->id,
        'user_id' => User::factory(),
        'amount' => 7300,
        'currency' => 'GHS',
        'status' => TransactionStatusEnum::pending->value,
    ]);

    return [$transaction, $invoice, $instrument];
}

test('a synchronous success response records the transaction success and settles the invoice', function () {
    [$transaction, $invoice] = aPendingSettlementTransaction();
    Http::fake(['*/transaction/charge_authorization' => Http::response([
        'status' => true,
        'data' => ['reference' => $transaction->reference, 'status' => 'success', 'amount' => 7300, 'currency' => 'GHS', 'gateway_response' => 'Approved'],
    ], 200)]);

    ProcessOrganizationInvoiceSettlementJob::dispatchSync($transaction->id);

    expect($transaction->fresh()->status)->toBe(TransactionStatusEnum::success->value);
    expect($invoice->fresh()->status)->toBe(OrganizationInvoiceStatusEnum::settled->value);
});

test('a declined charge records the transaction and invoice as failed', function () {
    [$transaction, $invoice] = aPendingSettlementTransaction();
    Http::fake(['*/transaction/charge_authorization' => Http::response([
        'status' => true,
        'data' => ['reference' => $transaction->reference, 'status' => 'failed', 'gateway_response' => 'Declined'],
    ], 200)]);

    ProcessOrganizationInvoiceSettlementJob::dispatchSync($transaction->id);

    expect($transaction->fresh()->status)->toBe(TransactionStatusEnum::failed->value);
    expect($invoice->fresh()->status)->toBe(OrganizationInvoiceStatusEnum::failed->value);
});

test('a 4xx from Paystack records a definite failure', function () {
    [$transaction, $invoice] = aPendingSettlementTransaction();
    Http::fake(['*/transaction/charge_authorization' => Http::response(['status' => false, 'message' => 'Declined'], 400)]);

    ProcessOrganizationInvoiceSettlementJob::dispatchSync($transaction->id);

    expect($transaction->fresh()->status)->toBe(TransactionStatusEnum::failed->value);
    expect($invoice->fresh()->status)->toBe(OrganizationInvoiceStatusEnum::failed->value);
});

// Mirrors ProcessCounsellorPayoutJob's identical reasoning: a 5xx means we genuinely don't know
// whether the charge went through -- this must fail the QUEUED JOB (so its own retry re-attempts
// the SAME transaction/reference), never record a definite failure.
test('a 5xx from Paystack does not record a definite failure -- it fails the job for retry instead', function () {
    [$transaction, $invoice] = aPendingSettlementTransaction();
    Http::fake(['*/transaction/charge_authorization' => Http::response(['status' => false, 'message' => 'Internal server error'], 500)]);

    expect(fn () => ProcessOrganizationInvoiceSettlementJob::dispatchSync($transaction->id))
        ->toThrow(RequestException::class);

    expect($transaction->fresh()->status)->toBe(TransactionStatusEnum::pending->value);
    expect($invoice->fresh()->status)->toBe(OrganizationInvoiceStatusEnum::pending->value);
});

test('a retried job never calls Paystack again once the transaction has already reached a terminal status', function () {
    Http::fake(); // any call here would be a bug -- no stub means the test fails loudly if reached.
    [$transaction] = aPendingSettlementTransaction();
    $transaction->update(['status' => TransactionStatusEnum::success->value]);

    ProcessOrganizationInvoiceSettlementJob::dispatchSync($transaction->id);

    Http::assertNothingSent();
});

test('a mismatched reported amount on an otherwise-successful charge throws rather than silently recording success', function () {
    [$transaction] = aPendingSettlementTransaction();
    Http::fake(['*/transaction/charge_authorization' => Http::response([
        'status' => true,
        'data' => ['reference' => $transaction->reference, 'status' => 'success', 'amount' => 1, 'currency' => 'GHS', 'gateway_response' => 'Approved'],
    ], 200)]);

    expect(fn () => ProcessOrganizationInvoiceSettlementJob::dispatchSync($transaction->id))
        ->toThrow(TransactionException::class);
});

test('an organization with no payment instrument by the time the job runs records a failure', function () {
    [$transaction] = aPendingSettlementTransaction();
    OrganizationPaymentInstrument::query()->where('organization_id', $transaction->organization_id)->delete();
    Http::fake();

    ProcessOrganizationInvoiceSettlementJob::dispatchSync($transaction->id);

    expect($transaction->fresh()->status)->toBe(TransactionStatusEnum::failed->value);
    Http::assertNothingSent();
});
