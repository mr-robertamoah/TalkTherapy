<?php

use App\Enums\OrganizationInvoiceStatusEnum;
use App\Enums\TransactionStatusEnum;
use App\Enums\TransactionStatusSourceEnum;
use App\Exceptions\TransactionException;
use App\Jobs\ProcessOrganizationInvoiceSettlementJob;
use App\Models\Organization;
use App\Models\OrganizationInvoice;
use App\Models\OrganizationPaymentInstrument;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
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
    // TT-7.3b-f2/SCRUM-238: a successful settlement must never suspend the org's billing.
    expect($invoice->organization->fresh()->isBillingSuspended())->toBeFalse();
});

test('a declined charge records the transaction and invoice as failed, and suspends the organization\'s billing', function () {
    [$transaction, $invoice] = aPendingSettlementTransaction();
    Http::fake(['*/transaction/charge_authorization' => Http::response([
        'status' => true,
        'data' => ['reference' => $transaction->reference, 'status' => 'failed', 'gateway_response' => 'Declined'],
    ], 200)]);

    ProcessOrganizationInvoiceSettlementJob::dispatchSync($transaction->id);

    expect($transaction->fresh()->status)->toBe(TransactionStatusEnum::failed->value);
    expect($invoice->fresh()->status)->toBe(OrganizationInvoiceStatusEnum::failed->value);
    expect($invoice->organization->fresh()->isBillingSuspended())->toBeTrue();
});

// SCRUM-244: without this, an abandoned settlement charge left its invoice stuck in `pending`
// forever (the periodic sweep only re-claims `open` invoices), the org was never billed, and its
// counsellors never got paid for that period -- silently, with no failure ever surfaced. There is
// no human present on this server-to-server charge to ever complete whatever interactive step
// Paystack was waiting on, so this reuses the existing failed-handling path instead.
test('an abandoned charge is treated as a real failure, not left stuck in pending forever', function () {
    [$transaction, $invoice] = aPendingSettlementTransaction();
    Http::fake(['*/transaction/charge_authorization' => Http::response([
        'status' => true,
        'data' => ['reference' => $transaction->reference, 'status' => 'abandoned', 'gateway_response' => 'Abandoned'],
    ], 200)]);

    ProcessOrganizationInvoiceSettlementJob::dispatchSync($transaction->id);

    expect($transaction->fresh()->status)->toBe(TransactionStatusEnum::failed->value);
    expect($invoice->fresh()->status)->toBe(OrganizationInvoiceStatusEnum::failed->value);
    expect($invoice->organization->fresh()->isBillingSuspended())->toBeTrue();
});

test('a 4xx from Paystack records a definite failure and suspends the organization\'s billing', function () {
    [$transaction, $invoice] = aPendingSettlementTransaction();
    Http::fake(['*/transaction/charge_authorization' => Http::response(['status' => false, 'message' => 'Declined'], 400)]);

    ProcessOrganizationInvoiceSettlementJob::dispatchSync($transaction->id);

    expect($transaction->fresh()->status)->toBe(TransactionStatusEnum::failed->value);
    expect($invoice->fresh()->status)->toBe(OrganizationInvoiceStatusEnum::failed->value);
    expect($invoice->organization->fresh()->isBillingSuspended())->toBeTrue();
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

// SCRUM-376: a retry refused with a 4xx (typically "duplicate reference" because the earlier,
// timed-out charge actually went through) looks the real outcome up by the same reference.
// handle() is called directly on a fake-queue job so attempts() can be set above 1.
function settlementJobRunAsRetry(int $transactionId): void
{
    $job = (new ProcessOrganizationInvoiceSettlementJob($transactionId))->withFakeQueueInteractions();
    $job->job->attempts = 2;

    $job->handle();
}

test('a retry refused with a 4xx records success when Paystack verifies the charge by that reference succeeded', function () {
    [$transaction, $invoice] = aPendingSettlementTransaction();
    Http::fake([
        '*/transaction/verify/*' => Http::response([
            'status' => true,
            'data' => ['reference' => $transaction->reference, 'status' => 'success', 'amount' => 7300, 'currency' => 'GHS', 'gateway_response' => 'Approved'],
        ], 200),
        '*/transaction/charge_authorization' => Http::response(['status' => false, 'message' => 'Duplicate Transaction Reference'], 400),
    ]);

    settlementJobRunAsRetry($transaction->id);

    expect($transaction->fresh()->status)->toBe(TransactionStatusEnum::success->value);
    expect($invoice->fresh()->status)->toBe(OrganizationInvoiceStatusEnum::settled->value);
    expect($invoice->organization->fresh()->isBillingSuspended())->toBeFalse();
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/transaction/verify/'.$transaction->reference));
});

test('a retry refused with a 4xx still checks the verified amount before recording success', function () {
    [$transaction] = aPendingSettlementTransaction();
    Http::fake([
        '*/transaction/verify/*' => Http::response([
            'status' => true,
            'data' => ['reference' => $transaction->reference, 'status' => 'success', 'amount' => 1, 'currency' => 'GHS'],
        ], 200),
        '*/transaction/charge_authorization' => Http::response(['status' => false, 'message' => 'Duplicate Transaction Reference'], 400),
    ]);

    expect(fn () => settlementJobRunAsRetry($transaction->id))->toThrow(TransactionException::class);
});

test('a retry refused with a 4xx records failure when Paystack verifies the charge by that reference failed', function (string $paystackStatus) {
    [$transaction, $invoice] = aPendingSettlementTransaction();
    Http::fake([
        '*/transaction/verify/*' => Http::response([
            'status' => true,
            'data' => ['reference' => $transaction->reference, 'status' => $paystackStatus, 'gateway_response' => 'Declined'],
        ], 200),
        '*/transaction/charge_authorization' => Http::response(['status' => false, 'message' => 'Duplicate Transaction Reference'], 400),
    ]);

    settlementJobRunAsRetry($transaction->id);

    expect($transaction->fresh()->status)->toBe(TransactionStatusEnum::failed->value);
    expect($invoice->fresh()->status)->toBe(OrganizationInvoiceStatusEnum::failed->value);
    expect($invoice->organization->fresh()->isBillingSuspended())->toBeTrue();
})->with(['failed']);

test('a retry refused with a 4xx rethrows and leaves the settlement untouched when the lookup is inconclusive', function (Closure $verifyResponse) {
    [$transaction, $invoice] = aPendingSettlementTransaction();
    Http::fake([
        '*/transaction/verify/*' => $verifyResponse,
        '*/transaction/charge_authorization' => Http::response(['status' => false, 'message' => 'Duplicate Transaction Reference'], 400),
    ]);

    expect(fn () => settlementJobRunAsRetry($transaction->id))->toThrow(RequestException::class);

    expect($transaction->fresh()->status)->toBe(TransactionStatusEnum::pending->value);
    expect($invoice->fresh()->status)->toBe(OrganizationInvoiceStatusEnum::pending->value);
})->with([
    'reference not found' => fn () => fn () => Http::response(['status' => false, 'message' => 'Transaction reference not found'], 400),
    'still processing' => fn () => fn () => Http::response(['status' => true, 'data' => ['status' => 'ongoing']], 200),
    // Security review: "abandoned" is also how Paystack reports a charge not attempted yet.
    'abandoned' => fn () => fn () => Http::response(['status' => true, 'data' => ['status' => 'abandoned']], 200),
    'another reference' => fn () => fn () => Http::response(['status' => true, 'data' => ['reference' => 'org_settlement_other', 'status' => 'failed']], 200),
    'Paystack unreachable' => fn () => fn () => throw new ConnectionException('timed out'),
]);

test('a first attempt refused with a 4xx is still a definite failure, with no lookup', function () {
    [$transaction] = aPendingSettlementTransaction();
    Http::fake(['*/transaction/charge_authorization' => Http::response(['status' => false, 'message' => 'Declined'], 400)]);

    $job = (new ProcessOrganizationInvoiceSettlementJob($transaction->id))->withFakeQueueInteractions();
    $job->handle();

    expect($transaction->fresh()->status)->toBe(TransactionStatusEnum::failed->value);
    Http::assertSentCount(1);
});

// SCRUM-378: a status recorded from the lookup is audited as such, not as the settlement charge's own answer.
test('a status recorded from a retry lookup has its own status-history source', function (string $paystackStatus) {
    [$transaction] = aPendingSettlementTransaction();
    Http::fake([
        '*/transaction/verify/*' => Http::response([
            'status' => true,
            'data' => ['reference' => $transaction->reference, 'status' => $paystackStatus, 'amount' => 7300, 'currency' => 'GHS'],
        ], 200),
        '*/transaction/charge_authorization' => Http::response(['status' => false, 'message' => 'Duplicate Transaction Reference'], 400),
    ]);

    settlementJobRunAsRetry($transaction->id);

    expect($transaction->fresh()->statusHistories()->latest('id')->first()->source)
        ->toBe(TransactionStatusSourceEnum::retryLookup->value);
})->with(['success', 'failed']);

test('a status recorded from the settlement charge itself is still sourced as org settlement', function () {
    [$transaction] = aPendingSettlementTransaction();
    Http::fake(['*/transaction/charge_authorization' => Http::response([
        'status' => true,
        'data' => ['status' => 'success', 'amount' => 7300, 'currency' => 'GHS'],
    ], 200)]);

    ProcessOrganizationInvoiceSettlementJob::dispatchSync($transaction->id);

    expect($transaction->fresh()->statusHistories()->latest('id')->first()->source)
        ->toBe(TransactionStatusSourceEnum::orgSettlement->value);
});
