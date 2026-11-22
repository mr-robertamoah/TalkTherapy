<?php

namespace App\Actions\Transaction;

use App\Actions\Action;
use App\Actions\Star\RevokeCounsellorStarsAction;
use App\Enums\StarSourceEnum;
use App\Models\GroupTherapy;
use App\Models\OrganizationInvoice;
use App\Models\OrganizationInvoiceLine;
use App\Models\Refund;
use App\Models\Session;
use App\Models\Therapy;
use App\Support\SessionPayments;
use Illuminate\Support\Facades\Log;

// TT-7.11b/SCRUM-471: a refunded session's star is taken back. Called after commit by RecordRefundStatusAction when a
// refund first moves to SUCCESS (personal and org-financed alike -- both go through it), so a webhook retry, which
// finds the refund already SUCCESS, never gets here; the delete is idempotent anyway.
//
// The sessions a payment covered:
// - `for` a Session (paid per session): that session.
// - `for` a Therapy or GroupTherapy (paid per therapy): every session of it.
// - `for` an OrganizationInvoice (a retainer settlement): every session on the invoice's lines.
// - anything else (e.g. an Organization's payment-instrument registration): none.
//
// A group's sessions are paid by each member: one member's refund leaves them paid while another member's payment for
// the same thing still stands, so nothing is revoked then. When the last standing payer refunds, the stars go -- even
// if other members were covered without paying (free places, an organisation's retainer); that is logged.
//
// Only a full refund revokes. Refunds are full-only today (EnsureTransactionIsRefundEligibleAction); a refund that
// leaves part of the payment standing keeps the stars, since which sessions it covers would be a guess.
class RevokeStarsForRefundAction extends Action
{
    public function execute(Refund $refund): int
    {
        $transaction = $refund->transaction;

        if (! $transaction) {
            return 0;
        }

        if ($refund->amount < $transaction->amount) {
            Log::info('A partial refund keeps the stars of the sessions its payment covered.', [
                'refund_id' => $refund->id,
                'transaction_id' => $transaction->id,
            ]);

            return 0;
        }

        if ($this->isForAGroup($transaction->for_type, (int) $transaction->for_id)) {
            if (SessionPayments::anotherStands($transaction)) {
                return 0;
            }

            Log::info('The last standing payment for a group was refunded; its sessions\' stars are revoked.', [
                'refund_id' => $refund->id,
                'transaction_id' => $transaction->id,
            ]);
        }

        return RevokeCounsellorStarsAction::new()->forStarreables(
            StarSourceEnum::sessionHeld,
            (new Session)->getMorphClass(),
            $this->sessionIds($transaction->for_type, (int) $transaction->for_id),
        );
    }

    private function isForAGroup(?string $forType, int $forId): bool
    {
        return $forType === (new GroupTherapy)->getMorphClass()
            || ($forType === (new Session)->getMorphClass()
                && Session::query()->whereKey($forId)->where('for_type', (new GroupTherapy)->getMorphClass())->exists());
    }

    private function sessionIds(?string $forType, int $forId): array
    {
        return match ($forType) {
            (new Session)->getMorphClass() => [$forId],
            (new Therapy)->getMorphClass(), (new GroupTherapy)->getMorphClass() => Session::query()
                ->where('for_type', $forType)
                ->where('for_id', $forId)
                ->pluck('id')
                ->all(),
            (new OrganizationInvoice)->getMorphClass() => OrganizationInvoiceLine::query()
                ->where('organization_invoice_id', $forId)
                ->whereNotNull('session_id')
                ->distinct()
                ->pluck('session_id')
                ->all(),
            default => [],
        };
    }
}
