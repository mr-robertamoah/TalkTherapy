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

    // A payout is terminal once succeeded/failed -- mirrors RecordCounsellorPayoutStatusAction's
    // own guard, but checked here, before ever calling Paystack (security review, second pass):
    // without this, a retried job (e.g. after this job itself failed on a 5xx and the queue
    // retried it) could re-send the SAME reference to Paystack a second time even though a
    // transfer.success webhook had already landed for it in the meantime -- Paystack would then
    // reject the duplicate reference with a 4xx, which this job's own catch block treats as a
    // genuine failure and releases the earnings, silently ignoring that the transfer had in fact
    // already succeeded.
    private const TERMINAL_STATUSES = [
        CounsellorPayoutStatusEnum::succeeded->value,
        CounsellorPayoutStatusEnum::failed->value,
    ];

    public function handle(): void
    {
        $payout = CounsellorPayout::find($this->payoutId);

        if (! $payout || in_array($payout->status, self::TERMINAL_STATUSES, true)) {
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
