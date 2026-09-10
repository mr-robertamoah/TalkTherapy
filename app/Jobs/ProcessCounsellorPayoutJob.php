<?php

namespace App\Jobs;

use App\Actions\Payout\RecordCounsellorPayoutStatusAction;
use App\Enums\CounsellorPayoutStatusEnum;
use App\Enums\CounsellorPayoutStatusSourceEnum;
use App\Models\CounsellorPayout;
use App\Services\Paystack\PaystackClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

// TT-7.6c/SCRUM-227: the real Paystack Transfer call, isolated here rather than inline in
// TriggerCounsellorPayoutAction -- dispatched only after that action's DB transaction commits
// (see its own comment on why), so this job never runs against a payout/claimed-earnings state
// that could still be rolled back.
class ProcessCounsellorPayoutJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(private int $payoutId)
    {
        //
    }

    // SCRUM-255 (security-engineer finding, HIGH, second pass): checking "not yet terminal" here
    // is NOT the same as "Paystack hasn't been called yet" -- a payout sits in `processing`
    // (non-terminal) for as long as an async Paystack response is outstanding, and
    // WithoutOverlapping's lock below only blocks a TRULY concurrent redelivery; it releases the
    // instant this method returns, which happens the moment this job records `processing` and
    // exits normally. A redelivery arriving any time after that -- the common case for a
    // live-mode async Transfer, per this job's own comment further down -- would find the lock
    // free and, under the old "skip only succeeded/failed" guard, call Paystack a SECOND time.
    // `pending` is the ONLY status in which this job has never yet called Paystack for this
    // payout -- every other status (processing, succeeded, failed) means either an attempt
    // already happened, or the outcome is final. Proceeding only from `pending` closes this for
    // good, independent of whatever the WithoutOverlapping lock's state happens to be. Mirrors
    // ProcessRefundJob's identical fix.
    private const ELIGIBLE_STATUS = CounsellorPayoutStatusEnum::pending->value;

    // Serializes a genuinely CONCURRENT redelivery (a visibility-timeout mid-flight, before this
    // job has even returned) so two workers can never both pass the ELIGIBLE_STATUS check above
    // at the same instant and both call Paystack. Deliberately a belt-and-braces addition to (not
    // a replacement for) that check -- see its own comment for why the status check alone is what
    // actually closes the ticket's named gap. expireAfter comfortably covers a slow Paystack
    // response (no explicit HTTP client timeout is set on PaystackClient, so Laravel's 30s
    // default applies) without leaving a crashed worker's lock stuck indefinitely.
    public function middleware(): array
    {
        return [(new WithoutOverlapping($this->payoutId))->releaseAfter(5)->expireAfter(120)];
    }

    public function handle(): void
    {
        $payout = CounsellorPayout::find($this->payoutId);

        if (! $payout || $payout->status !== self::ELIGIBLE_STATUS) {
            return;
        }

        $counsellor = $payout->counsellor;
        $payoutAccount = $counsellor->payoutAccount;

        try {
            $response = PaystackClient::new()->initiateTransfer([
                'source' => 'balance',
                'reason' => 'TalkTherapy counsellor payout',
                'amount' => $payout->amount,
                'currency' => $payout->currency,
                'reference' => $payout->reference,
                'recipient' => $payoutAccount->recipient_code,
            ]);
        } catch (RequestException $exception) {
            // Reviewer finding: a 5xx from Paystack means we genuinely don't know whether the
            // transfer was actually processed despite the error -- recording it as a definite
            // failure would release the claimed earnings for a fresh TriggerCounsellorPayoutAction
            // attempt, which mints a NEW CounsellorPayout with a NEW reference, risking a real
            // double-payment if the original transfer did go through. Rethrowing instead fails
            // this queued job and lets its own retry (this app's default queue backoff/attempts)
            // re-attempt the SAME payout/reference Paystack would recognize as a duplicate --
            // never a fresh one. Only a genuine 4xx (Paystack rejecting the request outright --
            // bad recipient, insufficient platform balance, etc.) is a real, definite failure.
            // A connection-level failure (Paystack unreachable) throws ConnectionException, which
            // this catch never even sees, and already propagates/retries the same way.
            if ($exception->response->serverError()) {
                throw $exception;
            }

            RecordCounsellorPayoutStatusAction::new()->execute(
                $payout,
                CounsellorPayoutStatusEnum::failed->value,
                CounsellorPayoutStatusSourceEnum::initiate->value,
                'Paystack could not initiate this transfer.'
            );

            return;
        }

        $transferCode = $response['data']['transfer_code'] ?? null;

        if ($transferCode) {
            $payout->update(['transfer_code' => $transferCode]);
        }

        // Paystack's test-mode Transfers commonly resolve synchronously ('success'), while
        // live-mode Transfers are typically asynchronous (a 'pending'/'otp' response, resolved
        // later by a transfer.success/transfer.failed/transfer.reversed webhook) -- this dev
        // environment has no live Paystack credentials to confirm either behavior directly
        // (same limitation noted on TT-7.4b's Playwright verification), so both paths are
        // handled: a terminal response here is recorded immediately, and the webhook
        // (ProcessPaystackWebhookJob) is also wired for the async case regardless.
        $status = match ($response['data']['status'] ?? null) {
            'success' => CounsellorPayoutStatusEnum::succeeded->value,
            'failed', 'reversed' => CounsellorPayoutStatusEnum::failed->value,
            default => CounsellorPayoutStatusEnum::processing->value,
        };

        RecordCounsellorPayoutStatusAction::new()->execute(
            $payout->fresh(),
            $status,
            CounsellorPayoutStatusSourceEnum::initiate->value,
            $response['data']['reason'] ?? null
        );
    }
}
