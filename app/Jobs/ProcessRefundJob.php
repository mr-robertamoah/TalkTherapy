<?php

namespace App\Jobs;

use App\Actions\Transaction\RecordRefundStatusAction;
use App\Enums\RefundStatusEnum;
use App\Enums\RefundStatusSourceEnum;
use App\Models\Refund;
use App\Services\Paystack\PaystackClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

// TT-7.7d/SCRUM-252: the real Paystack refund call, isolated here rather than inline in
// RespondToRefundRequestAction -- dispatched only after that action's DB transaction commits (see
// its own comment on why), so this job never runs against a Refund row that could still be
// rolled back. Mirrors ProcessCounsellorPayoutJob's identical shape for the same class of
// "real, external, money-moving call triggered by our own system" job.
class ProcessRefundJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(private int $refundId)
    {
        //
    }

    // Security-engineer finding: back-to-back immediate retries give neither Paystack's own async
    // resolution nor an incoming webhook any time to settle before this job re-attempts -- mirrors
    // the same backoff ProcessCounsellorPayoutJob should also have (not itself fixed here; that
    // job's own identical gap is out of this ticket's scope).
    public $backoff = [30, 120, 600];

    // SCRUM-255 (security-engineer finding, HIGH, second pass): checking "not yet terminal" here
    // is NOT the same as "Paystack hasn't been called yet" -- a refund sits in `processing`
    // (non-terminal) for as long as an async Paystack response is outstanding, and
    // WithoutOverlapping's lock below only blocks a TRULY concurrent redelivery; it releases the
    // instant this method returns, which happens the moment this job records `processing` and
    // exits normally. A redelivery arriving any time after that -- the common case for a
    // live-mode async Transfer/refund, per this job's own comment further down -- would find the
    // lock free and, under the old "skip only success/failed" guard, call Paystack a SECOND time.
    // `pending` is the ONLY status in which this job has never yet called Paystack for this
    // refund -- every other status (processing, success, failed) means either an attempt already
    // happened, or the outcome is final. Proceeding only from `pending` closes this for good,
    // independent of whatever the WithoutOverlapping lock's state happens to be.
    private const ELIGIBLE_STATUS = RefundStatusEnum::pending->value;

    // Serializes a genuinely CONCURRENT redelivery (a visibility-timeout mid-flight, before this
    // job has even returned) so two workers can never both pass the ELIGIBLE_STATUS check above
    // at the same instant and both call Paystack. Deliberately a belt-and-braces addition to (not
    // a replacement for) that check -- see its own comment for why the status check alone is what
    // actually closes the ticket's named gap. expireAfter comfortably covers a slow Paystack
    // response (PaystackClient sets a 5s connect / 15s total timeout since SCRUM-272, well inside this)
    // without leaving a crashed worker's lock stuck indefinitely.
    public function middleware(): array
    {
        return [(new WithoutOverlapping($this->refundId))->releaseAfter(5)->expireAfter(120)];
    }

    public function handle(): void
    {
        $refund = Refund::find($this->refundId);

        if (! $refund || $refund->status !== self::ELIGIBLE_STATUS) {
            return;
        }

        $transaction = $refund->transaction;
        $source = RefundStatusSourceEnum::initiate->value;

        // Security review: an earlier attempt's response already gave us Paystack's refund id, so the
        // refund exists there. Ask about it rather than sending a second refund request and relying on
        // Paystack to refuse the duplicate. An unclear answer throws, so the queue tries again later.
        if ($refund->paystack_refund_id) {
            $response = $this->finalOutcomeOrRethrow($refund, new RuntimeException(
                "Refund {$refund->id} is known to Paystack but not yet final; will check again."
            ));
            $source = RefundStatusSourceEnum::retryLookup->value;
        } else {
            try {
                // Unlike initiateTransfer()/chargeAuthorization(), Paystack's refund endpoint takes
                // no caller-supplied idempotency reference -- it keys off the ORIGINAL transaction's
                // own reference (confirmed via Paystack's public docs during this ticket's spike; no
                // live sandbox credentials in this dev environment to verify empirically). `amount`
                // is deliberately omitted -- this epic is full-refund-only, and Paystack treats a
                // request with no `amount` as a full refund of the original transaction.
                //
                // Security-engineer finding: `merchant_note` deliberately does NOT forward the
                // client's own free-text refund reason ($refund->reason) -- on a mental-health
                // platform that text can plausibly contain sensitive personal/therapy-related detail,
                // and Paystack has no legitimate need for it to process a payment reversal. The
                // reason stays internal-only (already stored on `refunds.reason`); only this refund's
                // own internal reference is sent, purely for the merchant's own later lookup in the
                // Paystack dashboard.
                $response = PaystackClient::new()->refundTransaction([
                    'transaction' => $transaction->reference,
                    'merchant_note' => "TalkTherapy refund {$refund->reference}",
                ]);
            } catch (RequestException $exception) {
                // Security-engineer finding: a 429 (rate-limited) is not a real rejection signal --
                // treated like a 5xx (ambiguous, retry) rather than the definite 4xx failure below.
                // Same reasoning as ProcessCounsellorPayoutJob's identical guard for a genuine 5xx: we
                // genuinely don't know whether the refund was actually processed despite the error --
                // recording it as a definite failure here would be a real, false "you can try again"
                // signal to an admin, when Paystack may have already refunded the client. Rethrowing
                // instead fails this queued job and lets its own retry re-attempt against the SAME
                // transaction reference Paystack would recognize as already-refunded (a real 4xx) if
                // the original call did in fact succeed. Only a genuine, non-429 4xx (Paystack
                // rejecting the request outright) is a real, definite failure.
                if ($exception->response->serverError() || $exception->response->status() === 429) {
                    throw $exception;
                }

                // SCRUM-272 (security review): on a RETRY, a 4xx isn't a definite rejection either -- the
                // earlier attempt may have timed out AFTER Paystack processed it, and the retry is then
                // refused as a duplicate. Recording "failed" would invite a second, new transfer/charge.
                // SCRUM-378: so ask Paystack what actually became of this refund instead -- possible only
                // when an earlier attempt's response delivered Paystack's own refund id (stored below),
                // e.g. the POST went through but recording its outcome then failed. The case the retry
                // guard above exists for -- the first attempt timed out before any response, so no id was
                // ever seen -- still can't be looked up: Paystack's List Refunds documents no filter by
                // transaction. That one rethrows as before, for the refund.processed/refund.failed webhook
                // (matched by transaction reference) and the failed-job alert to resolve.
                if ($this->attempts() > 1) {
                    $response = $this->finalOutcomeOrRethrow($refund, $exception);
                    $source = RefundStatusSourceEnum::retryLookup->value;
                } else {
                    RecordRefundStatusAction::new()->execute(
                        $refund,
                        RefundStatusEnum::failed->value,
                        RefundStatusSourceEnum::initiate->value,
                        'Paystack could not process this refund.'
                    );

                    return;
                }
            }
        }

        // SCRUM-378: kept before (and outside) the status recording below, so a failure there
        // still leaves the id for a retry's lookup.
        $paystackRefundId = $response['data']['id'] ?? null;
        if (is_scalar($paystackRefundId) && (string) $paystackRefundId !== '' && $refund->paystack_refund_id !== (string) $paystackRefundId) {
            $refund->update(['paystack_refund_id' => (string) $paystackRefundId]);
        }

        // Paystack's refund status vocabulary (per its own docs) is 'pending'/'processed'/
        // 'failed' -- distinct from Transfer's 'success'/'failed'/'reversed'. A refund may
        // resolve synchronously in this same response, or asynchronously via a later
        // refund.processed/refund.failed webhook (ProcessPaystackWebhookJob) -- both paths are
        // handled, mirroring ProcessCounsellorPayoutJob's own identical dual-path comment.
        $status = match ($response['data']['status'] ?? null) {
            'processed' => RefundStatusEnum::success->value,
            'failed' => RefundStatusEnum::failed->value,
            default => RefundStatusEnum::processing->value,
        };

        RecordRefundStatusAction::new()->execute(
            $refund->fresh(),
            $status,
            $source,
            $response['data']['status'] ?? null
        );
    }

    // SCRUM-378: only a definite answer comes back -- 'processed' (refunded) or 'failed'. Anything
    // else (no stored id, Paystack's other refund statuses such as pending/processing/needs-attention,
    // a 404, Paystack unreachable) is ambiguous, so the original 4xx is rethrown: never a failure
    // guessed from an inconclusive lookup, never a success Paystack didn't state.
    private function finalOutcomeOrRethrow(Refund $refund, Throwable $exception): array
    {
        if (! $refund->paystack_refund_id) {
            throw $exception;
        }

        try {
            $response = PaystackClient::new()->fetchRefund($refund->paystack_refund_id);
        } catch (HttpClientException) {
            throw $exception;
        }

        if (! in_array($response['data']['status'] ?? null, ['processed', 'failed'], true)) {
            throw $exception;
        }

        // Same rule as the payout lookup: only this refund's own record counts -- its id, the
        // original transaction's reference, and the refund's amount and currency. Anything that
        // doesn't match stays pending for someone to check by hand.
        $data = $response['data'];
        if ((string) ($data['id'] ?? '') !== $refund->paystack_refund_id
            || ($data['transaction_reference'] ?? null) !== $refund->transaction->reference
            || (int) ($data['amount'] ?? -1) !== $refund->amount
            || strtoupper((string) ($data['currency'] ?? '')) !== strtoupper((string) $refund->currency)) {
            Log::warning('A refund lookup returned a refund that does not match this one; left for manual follow-up.', [
                'refund_id' => $refund->id,
            ]);

            throw $exception;
        }

        Log::info('A retried refund was refused by Paystack; recorded the outcome Paystack reports for its refund id instead.', [
            'refund_id' => $refund->id,
            'status' => $data['status'],
        ]);

        return $response;
    }
}
