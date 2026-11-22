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
use Illuminate\Support\Facades\Log;

// TT-7.11b/SCRUM-471: a refunded session's star is taken back. Called by RecordRefundStatusAction when a refund
// first moves to SUCCESS (personal and org-financed alike -- both go through it), so a webhook retry, which finds the
// refund already SUCCESS, never gets here; deleting is idempotent anyway.
//
// The sessions a transaction paid for:
// - `for` a Session (paid per session): that session.
// - `for` a Therapy or GroupTherapy (paid per therapy): every session of it.
// - `for` an OrganizationInvoice (a retainer settlement): every session on the invoice's lines.
// - anything else (e.g. an Organization's payment-instrument registration): none.
//
// Only a full refund revokes. Refunds are full-only today (EnsureTransactionIsRefundEligibleAction); should partial
// refunds arrive, one that leaves part of the payment standing keeps the stars, since which sessions it covers would
// be a guess.
class RevokeStarsForRefundedTransactionAction extends Action
{
    public function execute(Refund $refund): int
    {
        $transaction = $refund->transaction;

        if (! $transaction) {
            return 0;
        }

        if ($refund->amount < $transaction->amount) {
            Log::info('A partial refund keeps the stars of the sessions its transaction paid for.', [
                'refund_id' => $refund->id,
                'transaction_id' => $transaction->id,
            ]);

            return 0;
        }

        $revoked = 0;

        $sessions = Session::query()
            ->whereKey($this->sessionIds($transaction->for_type, (int) $transaction->for_id))
            ->get(['id']);

        foreach ($sessions as $session) {
            $revoked += RevokeCounsellorStarsAction::new()->forStarreable(StarSourceEnum::sessionHeld, $session);
        }

        return $revoked;
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
