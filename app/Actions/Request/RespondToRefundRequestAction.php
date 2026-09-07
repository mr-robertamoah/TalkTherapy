<?php

namespace App\Actions\Request;

use App\Actions\Action;
use App\Actions\Transaction\EnsureTransactionIsRefundEligibleAction;
use App\DTOs\RequestResponseDTO;
use App\Enums\RefundStatusEnum;
use App\Enums\RefundStatusSourceEnum;
use App\Enums\RequestStatusEnum;
use App\Models\Refund;
use App\Models\Request;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// TT-7.7a/SCRUM-249: mirrors RespondToOrganizationCounsellorCompensationRequestAction's shape --
// lock-then-mutate under one DB transaction, idempotent no-op if already responded to.
//
// Architect decision (documentation/decision-log.md's 2026-09-02 SCRUM-223 entry): the real
// Paystack refund call is deliberately isolated in its own queued job (TT-7.7d, not yet built),
// never called inline from within this shared dispatcher -- RespondToRequestAction's per-type
// `if`-chain is already on record (SCRUM-119/120) as growing debt, and its only tested
// idempotency guarantee was built for simple internal-state flips, not "did we already call a
// third-party payment API for this." This action's own job ends at creating the `Refund` row in
// PENDING -- dispatching the execution job is TT-7.7d's addition to this same method.
class RespondToRefundRequestAction extends Action
{
    public function execute(RequestResponseDTO $requestResponseDTO)
    {
        return DB::transaction(function () use ($requestResponseDTO) {
            $request = Request::query()->lockForUpdate()->findOrFail($requestResponseDTO->request->id);

            if ($request->status != RequestStatusEnum::pending->value) {
                return $request;
            }

            $status = is_null($requestResponseDTO->response)
                ? RequestStatusEnum::rejected->value
                : strtoupper($requestResponseDTO->response);

            $request->update(['status' => $status]);
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

            // Reject is a flat decline -- no Refund row is ever created. Outcome notifications to
            // the client (either direction) are TT-7.7e's scope, not built yet.

            return $request;
        });
    }
}
