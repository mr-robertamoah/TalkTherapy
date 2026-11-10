<?php

namespace App\Actions\Transaction;

use App\Actions\Action;
use App\Actions\Request\CreateRequestAction;
use App\DTOs\CreateRequestDTO;
use App\Enums\RequestTypeEnum;
use App\Exceptions\TransactionException;
use App\Models\Request as ModelsRequest;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\RefundRequestedNotification;
use Illuminate\Support\Facades\Notification;

// TT-7.7b/SCRUM-250: the client-facing "ask" -- TT-7.7a already built the data model and
// EnsureTransactionIsRefundEligibleAction; this is the first thing that actually calls it to
// create a `refund`-type Request. Deliberately its own action (not folded into RequestService,
// which has no generic "create a request" method today) mirroring this codebase's established
// one-bespoke-action-per-request-type convention (e.g. ProposeOrganizationCounsellorCompensationChangeAction).
class RequestRefundAction extends Action
{
    public function execute(?User $user, ?Transaction $transaction, ?string $reason): ModelsRequest
    {
        // Security-engineer finding: a missing transaction and one that exists but belongs to
        // someone else deliberately share the same status/message -- distinguishing "doesn't
        // exist" from "exists but isn't yours" would let a caller enumerate transactionId values
        // as an existence oracle for no real benefit.
        //
        // Confirmed via TT-7.3b/ChargeOrganizationForModelAction: `transaction.user_id` is always
        // the client, never the org/admin, even for an org-financed transaction -- a plain
        // ownership check is sufficient regardless of `organization_id`.
        if (is_null($user) || is_null($transaction) || $transaction->user_id !== $user->id) {
            throw new TransactionException('You are not authorized to request a refund for this transaction.', 403);
        }

        if (is_null($reason) || trim($reason) === '') {
            throw new TransactionException('A reason is required to request a refund.', 422);
        }

        EnsureTransactionIsRefundEligibleAction::new()->execute($transaction);

        $request = CreateRequestAction::new()->execute(
            CreateRequestDTO::new()->fromArray([
                'from' => $user,
                'to' => null,
                'for' => $transaction,
                'type' => RequestTypeEnum::refund->value,
                'data' => ['reason' => trim($reason)],
            ])
        );

        // Mirrors OrganizationBillingSuspensionMayBeResolvedNotification/PayoutFailedNotification's
        // own "2 random admins" convention -- refund requests have no single targeted `to` (any
        // admin may respond), so there is no one specific recipient to notify directly.
        $admins = User::query()->whereAdmin()->inRandomOrder()->limit(2)->get();

        if ($admins->isNotEmpty()) {
            Notification::send($admins, new RefundRequestedNotification($request));
        }

        return $request;
    }
}
