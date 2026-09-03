<?php

use App\Enums\CounsellorEarningStatusEnum;
use App\Enums\CounsellorPayoutStatusEnum;
use App\Jobs\ProcessCounsellorPayoutJob;
use App\Models\Counsellor;
use App\Models\CounsellorEarning;
use App\Models\CounsellorPayout;
use App\Models\CounsellorPayoutAccount;
use App\Models\Therapy;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

function aPendingPayoutWithClaimedEarning(): array
{
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()]);
    CounsellorPayoutAccount::factory()->create(['counsellor_id' => $counsellor->id, 'recipient_code' => 'RCP_test']);
    $payout = CounsellorPayout::factory()->create(['counsellor_id' => $counsellor->id, 'amount' => 10000]);

    $therapy = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => User::factory()]);
    $transaction = Transaction::factory()->create(['for_type' => Therapy::class, 'for_id' => $therapy->id]);
    $earning = CounsellorEarning::factory()->create([
        'transaction_id' => $transaction->id,
        'counsellor_id' => $counsellor->id,
        'counsellor_payout_id' => $payout->id,
        'net_amount' => 10000,
        'status' => CounsellorEarningStatusEnum::processing->value,
    ]);

    return [$payout, $earning];
}

test('a synchronous success response from Paystack records the payout as succeeded immediately', function () {
    Http::fake(['*/transfer' => Http::response([
        'status' => true,
        'data' => ['transfer_code' => 'TRF_1', 'status' => 'success'],
    ], 200)]);
    [$payout, $earning] = aPendingPayoutWithClaimedEarning();

    ProcessCounsellorPayoutJob::dispatchSync($payout->id);

    expect($payout->fresh()->status)->toBe(CounsellorPayoutStatusEnum::succeeded->value);
    expect($payout->fresh()->transfer_code)->toBe('TRF_1');
    expect($earning->fresh()->status)->toBe(CounsellorEarningStatusEnum::paidOut->value);
});

test('an asynchronous pending response leaves the payout processing, awaiting the webhook', function () {
    Http::fake(['*/transfer' => Http::response([
        'status' => true,
        'data' => ['transfer_code' => 'TRF_2', 'status' => 'pending'],
    ], 200)]);
    [$payout, $earning] = aPendingPayoutWithClaimedEarning();

    ProcessCounsellorPayoutJob::dispatchSync($payout->id);

    expect($payout->fresh()->status)->toBe(CounsellorPayoutStatusEnum::processing->value);
    expect($earning->fresh()->status)->toBe(CounsellorEarningStatusEnum::processing->value);
});

test('a 4xx (client error) response from Paystack records the payout as a definite failure', function () {
    Http::fake(['*/transfer' => Http::response(['status' => false, 'message' => 'Insufficient balance'], 400)]);
    [$payout, $earning] = aPendingPayoutWithClaimedEarning();

    ProcessCounsellorPayoutJob::dispatchSync($payout->id);

    expect($payout->fresh()->status)->toBe(CounsellorPayoutStatusEnum::failed->value);
    expect($earning->fresh()->status)->toBe(CounsellorEarningStatusEnum::pending->value);
});

// Reviewer finding: a 5xx means Paystack may or may not have actually processed the transfer --
// recording it as a definite failure would release the earnings for a fresh
// TriggerCounsellorPayoutAction attempt, which mints a NEW payout/reference, risking a real
// double-payment if the original transfer did go through. This must instead fail the QUEUED JOB
// itself (so its own retry re-attempts the SAME payout/reference), not the payout record.
test('a 5xx (server error) response from Paystack does not record a definite failure -- it fails the job for retry instead', function () {
    Http::fake(['*/transfer' => Http::response(['status' => false, 'message' => 'Internal server error'], 500)]);
    [$payout, $earning] = aPendingPayoutWithClaimedEarning();

    expect(fn () => ProcessCounsellorPayoutJob::dispatchSync($payout->id))
        ->toThrow(RequestException::class);

    expect($payout->fresh()->status)->toBe(CounsellorPayoutStatusEnum::pending->value);
    expect($earning->fresh()->status)->toBe(CounsellorEarningStatusEnum::processing->value);
});

// Security review (second pass): closes the residual race where a job retried after a 5xx could
// re-send the same reference to Paystack even though a transfer.success webhook had already
// landed for it in the meantime -- Paystack's own "duplicate reference" 4xx would otherwise be
// treated as a genuine failure, releasing earnings that had actually already been paid out.
test('a retried job never calls Paystack again once the payout has already reached a terminal status', function () {
    Http::fake(); // any call here would be a bug -- no stub means the test fails loudly if reached.
    [$payout, $earning] = aPendingPayoutWithClaimedEarning();
    $payout->update(['status' => CounsellorPayoutStatusEnum::succeeded->value]);

    ProcessCounsellorPayoutJob::dispatchSync($payout->id);

    Http::assertNothingSent();
});
