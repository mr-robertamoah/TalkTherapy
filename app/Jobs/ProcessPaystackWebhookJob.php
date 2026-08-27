<?php

namespace App\Jobs;

use App\Actions\Transaction\EnsureTransactionAmountAndCurrencyMatchAction;
use App\Actions\Transaction\FindTransactionByReferenceAction;
use App\Actions\Transaction\RecordTransactionStatusAction;
use App\Enums\TransactionStatusEnum;
use App\Enums\TransactionStatusSourceEnum;
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
        $reference = $this->payload['data']['reference'] ?? null;
        $event = $this->payload['event'] ?? null;

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
            $this->payload['data']['gateway_response'] ?? null
        );
    }
}
