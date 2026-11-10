<?php

namespace App\Actions\Request;

use App\Actions\Action;
use App\Actions\Transaction\EnsureTransactionIsRefundEligibleAction;
use App\DTOs\RequestResponseDTO;
use App\Enums\RefundStatusEnum;
use App\Enums\RefundStatusSourceEnum;
use App\Enums\RequestStatusEnum;
use App\Exceptions\BadRequestException;
use App\Exceptions\TransactionException;
use App\Jobs\ProcessRefundJob;
use App\Models\Refund;
use App\Models\Request;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\RefundRequestRejectedNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// TT-7.7a/SCRUM-249: mirrors RespondToOrganizationCounsellorCompensationRequestAction's shape --
// lock-then-mutate under one DB transaction, idempotent no-op if already responded to.
//
// Architect decision (documentation/decision-log.md's 2026-09-02 SCRUM-223 entry): the real
// Paystack refund call is deliberately isolated in its own queued job (TT-7.7d/SCRUM-252,
// ProcessRefundJob), never called inline from within this shared dispatcher -- RespondToRequestAction's
// per-type `if`-chain is already on record (SCRUM-119/120) as growing debt, and its only tested
// idempotency guarantee was built for simple internal-state flips, not "did we already call a
// third-party payment API for this."
class RespondToRefundRequestAction extends Action
{
    public function execute(RequestResponseDTO $requestResponseDTO)
    {
        // TT-7.7d/SCRUM-252: captured outside the transaction closure so the job dispatch below
        // can happen strictly after it commits -- mirrors TriggerCounsellorPayoutAction's own
        // identical pattern (this queue connection's after_commit config is false, so a job
        // dispatched from inside an open transaction could be picked up by a worker before the
        // Refund row it describes is actually visible on that worker's own connection).
        $refund = null;

        $request = DB::transaction(function () use ($requestResponseDTO, &$refund) {
            $request = Request::query()->lockForUpdate()->findOrFail($requestResponseDTO->request->id);

            if ($request->status != RequestStatusEnum::pending->value) {
                return $request;
            }

            $status = is_null($requestResponseDTO->response)
                ? RequestStatusEnum::rejected->value
                : strtoupper($requestResponseDTO->response);

            // TT-7.7c/SCRUM-251: unlike every other RespondTo*RequestAction's `reason` (generic,
            // optional -- EnsureRequestResponseReasonIsValidAction only checks type/length, never
            // requires it), a refund decline is required to explain why to the client, since there
            // is no further "outcome" step coming later the way accept has (TT-7.7d/e) -- reject
            // is this request's own final word. Checked before any write, so a missing reason
            // fails closed without ever touching the Request row. BadRequestException (not
            // TransactionException) to match EnsureRequestResponseReasonIsValidAction's own
            // exception type for this same "bad reason input" class of error.
            if ($status === RequestStatusEnum::rejected->value && (is_null($requestResponseDTO->reason) || trim($requestResponseDTO->reason) === '')) {
                throw new BadRequestException('A reason is required to reject a refund request.', 422);
            }

            // Distinct data key from the client's own ask-time `reason` (set by RequestRefundAction)
            // -- this is the admin's rejection note, never overwriting why the client originally asked.
            $request->update([
                'status' => $status,
                'data' => $status === RequestStatusEnum::rejected->value
                    ? array_merge($request->data, ['rejectionReason' => trim($requestResponseDTO->reason)])
                    : $request->data,
            ]);
            $request = $request->refresh();

            if ($status === RequestStatusEnum::accepted->value) {
                // Security-engineer finding: locking only the Request row above isn't enough --
                // two DIFFERENT pending refund Requests for the SAME transaction (nothing in this
                // ticket prevents that at creation time; TT-7.7b's one-active-request-per-transaction
                // guard is what will) could otherwise both pass EnsureTransactionIsRefundEligibleAction's
                // own plain, non-locking reads before either commits its Refund row -- the exact
                // "lockForUpdate() on one row is not enough if the invariant-checking query itself
                // is a plain read" bug class already hit and fixed once in this codebase. Locking
                // the Transaction row here serializes any concurrent accept attempts for the same
                // transaction, so the second one's re-check below correctly sees the first's
                // already-committed Refund row.
                $transaction = Transaction::query()->lockForUpdate()->findOrFail($request->for_id);

                // Re-validated at approve time, not just at ask time -- mirrors
                // RespondToOrganizationCounsellorCompensationRequestAction's own re-check of
                // affiliation eligibility (something could have changed between ask and approve;
                // here, a second refund could conceivably have been approved for the same
                // transaction in the interim).
                EnsureTransactionIsRefundEligibleAction::new()->execute($transaction);

                $refund = Refund::query()->create([
                    'transaction_id' => $transaction->id,
                    'request_id' => $request->id,
                    'requested_by_id' => $request->from_id,
                    'reference' => 'refund_'.Str::uuid(),
                    'amount' => $transaction->amount,
                    'currency' => $transaction->currency,
                    'reason' => $request->data['reason'] ?? null,
                    'status' => RefundStatusEnum::pending->value,
                ]);

                $refund->statusHistories()->create([
                    'status' => RefundStatusEnum::pending->value,
                    'source' => RefundStatusSourceEnum::requested->value,
                    'message' => 'Refund approved by an administrator; awaiting execution.',
                ]);
            }

            // Reject is a flat decline -- no Refund row is ever created. Distinct from TT-7.7e's
            // own future "refund outcome" notification (sent once TT-7.7d's Paystack call actually
            // resolves) -- a reject has no further step coming, so the client is told now.
            if ($status === RequestStatusEnum::rejected->value) {
                $client = User::find($request->from_id);
                $client?->notify(new RefundRequestRejectedNotification($request));
            }

            return $request;
        });

        // TT-7.7d/SCRUM-252: dispatched AFTER the transaction above commits, not from inside it
        // (see this method's own top-of-function comment for why). Only fires when an accept
        // actually created a Refund row above -- a no-op idempotent re-response, or a reject,
        // leaves $refund null.
        if ($refund) {
            ProcessRefundJob::dispatch($refund->id);
        }

        return $request;
    }
}
