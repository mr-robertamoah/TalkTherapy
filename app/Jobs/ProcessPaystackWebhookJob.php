<?php

namespace App\Jobs;

use App\Actions\Payout\RecordCounsellorPayoutStatusAction;
use App\Actions\Transaction\EnsureTransactionAmountAndCurrencyMatchAction;
use App\Actions\Transaction\FindTransactionByReferenceAction;
use App\Actions\Transaction\RecordRefundStatusAction;
use App\Actions\Transaction\RecordTransactionStatusAction;
use App\Enums\CounsellorPayoutStatusEnum;
use App\Enums\CounsellorPayoutStatusSourceEnum;
use App\Enums\RefundStatusEnum;
use App\Enums\RefundStatusSourceEnum;
use App\Enums\TransactionStatusEnum;
use App\Enums\TransactionStatusSourceEnum;
use App\Models\CounsellorPayout;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

// Signature verification happens synchronously in the controller before this is ever dispatched
// -- this job only does the (potentially slower) status-recording work, so a sluggish handler
// can't make Paystack think the webhook delivery itself failed and retry unnecessarily. A
// failure here is caught and alerted on by AppService::alertAdminsOfFailedJob() (SCRUM-82), for
// free, since this is just another queued job.
class ProcessPaystackWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(private array $payload)
    {
        //
    }

    public function handle(): void
    {
        $event = (string) ($this->payload['event'] ?? '');

        // TT-7.6c/SCRUM-227: extends this existing job with transfer events rather than a second
        // controller/route -- Paystack only supports one webhook URL per integration, so a
        // second route would buy no isolation and duplicate signature-verification code
        // (architect decision, SCRUM-224 review).
        if (str_starts_with($event, 'transfer.')) {
            $this->handleTransferEvent($event);

            return;
        }

        // TT-7.7d/SCRUM-252: extends this existing job with refund events, same reasoning as the
        // transfer branch above (one webhook URL, no isolation benefit from a second route).
        if (str_starts_with($event, 'refund.')) {
            $this->handleRefundEvent($event);

            return;
        }

        $this->handleChargeEvent($event);
    }

    private function handleChargeEvent(string $event): void
    {
        $reference = $this->payload['data']['reference'] ?? null;

        $status = match ($event) {
            'charge.success' => TransactionStatusEnum::success->value,
            'charge.failed' => TransactionStatusEnum::failed->value,
            default => null,
        };

        if (! $status) {
            return;
        }

        $transaction = FindTransactionByReferenceAction::new()->execute($reference);

        if (! $transaction) {
            return;
        }

        // A mismatch here throws, which fails this job -- deliberately: AppService::
        // alertAdminsOfFailedJob() (SCRUM-82) already notifies admins on any queued-job failure,
        // so it doubles as the "flag for manual review" path this needs, without building a
        // second one (SCRUM-117).
        if ($status === TransactionStatusEnum::success->value) {
            EnsureTransactionAmountAndCurrencyMatchAction::new()->execute(
                $transaction,
                isset($this->payload['data']['amount']) ? (int) $this->payload['data']['amount'] : null,
                $this->payload['data']['currency'] ?? null,
                TransactionStatusSourceEnum::webhook->value
            );
        }

        RecordTransactionStatusAction::new()->execute(
            $transaction,
            $status,
            TransactionStatusSourceEnum::webhook->value,
            $this->payload['data']['gateway_response'] ?? null,
            $this->payload['data'] ?? null
        );
    }

    private function handleTransferEvent(string $event): void
    {
        $reference = $this->payload['data']['reference'] ?? null;

        $status = match ($event) {
            'transfer.success' => CounsellorPayoutStatusEnum::succeeded->value,
            // transfer.reversed is recorded identically to transfer.failed here -- see
            // RecordCounsellorPayoutStatusAction's own comment on why a reversal arriving after
            // an already-recorded success is deliberately NOT handled by this same branch.
            'transfer.failed', 'transfer.reversed' => CounsellorPayoutStatusEnum::failed->value,
            default => null,
        };

        if (! $status || ! $reference) {
            return;
        }

        $payout = CounsellorPayout::query()->whereReference($reference)->first();

        if (! $payout) {
            return;
        }

        RecordCounsellorPayoutStatusAction::new()->execute(
            $payout,
            $status,
            CounsellorPayoutStatusSourceEnum::webhook->value,
            $this->payload['data']['reason'] ?? $this->payload['data']['failure_reason'] ?? null
        );
    }

    // TT-7.7d/SCRUM-252: unlike the transfer branch above, Paystack's refund endpoint takes no
    // caller-supplied idempotency reference (confirmed during this ticket's spike) -- a refund
    // event payload identifies the ORIGINAL transaction it's about (via a nested `transaction`
    // object, or a flat `transaction_reference` field, depending on payload version), not a
    // refund-specific reference of our own. Correlated back to the one active (non-terminal)
    // Refund row for that transaction -- EnsureTransactionIsRefundEligibleAction already
    // guarantees at most one exists at a time.
    private function handleRefundEvent(string $event): void
    {
        $status = match ($event) {
            'refund.processed' => RefundStatusEnum::success->value,
            'refund.failed' => RefundStatusEnum::failed->value,
            default => null,
        };

        if (! $status) {
            return;
        }

        $originalReference = $this->payload['data']['transaction']['reference']
            ?? $this->payload['data']['transaction_reference']
            ?? null;

        $transaction = FindTransactionByReferenceAction::new()->execute($originalReference);

        if (! $transaction) {
            return;
        }

        $refund = $transaction->refunds()
            ->whereIn('status', [RefundStatusEnum::pending->value, RefundStatusEnum::processing->value])
            ->latest()
            ->first();

        if (! $refund) {
            return;
        }

        RecordRefundStatusAction::new()->execute(
            $refund,
            $status,
            RefundStatusSourceEnum::webhook->value,
            $this->payload['data']['status'] ?? null
        );
    }
}
