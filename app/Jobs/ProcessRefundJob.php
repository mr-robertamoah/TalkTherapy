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

    // A refund is terminal once success/failed -- mirrors ProcessCounsellorPayoutJob's own guard,
    // checked here before ever calling Paystack: without this, a retried job (e.g. after this job
    // itself failed on a 5xx and the queue retried it) could re-attempt a refund that a
    // refund.processed webhook had already resolved in the meantime.
    private const TERMINAL_STATUSES = [
        RefundStatusEnum::success->value,
        RefundStatusEnum::failed->value,
    ];

    public function handle(): void
    {
        $refund = Refund::find($this->refundId);

        if (! $refund || in_array($refund->status, self::TERMINAL_STATUSES, true)) {
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
