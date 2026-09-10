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
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

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
    // response (no explicit HTTP client timeout is set on PaystackClient, so Laravel's 30s
    // default applies) without leaving a crashed worker's lock stuck indefinitely.
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

            RecordRefundStatusAction::new()->execute(
                $refund,
                RefundStatusEnum::failed->value,
                RefundStatusSourceEnum::initiate->value,
                'Paystack could not process this refund.'
            );

            return;
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
            RefundStatusSourceEnum::initiate->value,
            $response['data']['status'] ?? null
        );
    }
}
