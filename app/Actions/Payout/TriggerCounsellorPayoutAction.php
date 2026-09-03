<?php

namespace App\Actions\Payout;

use App\Actions\Action;
use App\DTOs\TriggerPayoutDTO;
use App\Enums\CounsellorEarningStatusEnum;
use App\Enums\CounsellorPayoutStatusEnum;
use App\Enums\CounsellorPayoutStatusSourceEnum;
use App\Jobs\ProcessCounsellorPayoutJob;
use App\Models\CounsellorEarning;
use App\Models\CounsellorPayout;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// TT-7.6c/SCRUM-227: the highest-risk action in this ticket -- real money leaves the platform as
// a direct consequence of this succeeding. Idempotency mirrors RespondToRequestAction's existing
// lock-then-mutate idiom (architect recommendation): lock the rows about to be mutated, check
// their state under that lock, then mutate -- all inside one DB::transaction() -- so a concurrent
// second trigger (admin racing the counsellor, or a double-click) finds no `pending` earnings
// left to claim rather than claiming the same money twice.
class TriggerCounsellorPayoutAction extends Action
{
    public function execute(TriggerPayoutDTO $dto): CounsellorPayout
    {
        $counsellor = GetPayoutTargetCounsellorAction::new()->execute($dto);

        EnsureCounsellorHasPayoutDestinationAction::new()->execute($counsellor);

        $payoutAccount = $counsellor->payoutAccount;

        $payout = DB::transaction(function () use ($dto, $counsellor, $payoutAccount) {
            // Earnings are only claimable in the SAME currency as the counsellor's payout
            // destination -- this codebase has no cross-currency conversion (that's TT-7.9
            // territory), so an earning in a currency the destination can't receive simply stays
            // `pending` until either the destination's currency changes or multi-currency payout
            // support exists.
            $earnings = CounsellorEarning::query()
                ->where('counsellor_id', $counsellor->id)
                ->where('currency', $payoutAccount->currency)
                ->where('status', CounsellorEarningStatusEnum::pending->value)
                ->lockForUpdate()
                ->get();

            $availableAmount = (int) $earnings->sum('net_amount');

            EnsurePayoutMeetsMinimumThresholdAction::new()->execute($availableAmount, $payoutAccount->currency);

            $payout = CounsellorPayout::query()->create([
                'counsellor_id' => $counsellor->id,
                'initiated_by_id' => $dto->user->id,
                'reference' => 'payout_'.Str::uuid(),
                'amount' => $availableAmount,
                'currency' => $payoutAccount->currency,
                'status' => CounsellorPayoutStatusEnum::pending->value,
            ]);

            $payout->statusHistories()->create([
                'status' => CounsellorPayoutStatusEnum::pending->value,
                'source' => CounsellorPayoutStatusSourceEnum::initiate->value,
            ]);

            // Claim by flipping status AND recording which payout claimed it, inside the same
            // locked transaction that created $payout above -- a concurrent second trigger's own
            // `where('status', 'pending')` (evaluated after this transaction commits and its
            // locks release) then finds zero rows left to claim.
            CounsellorEarning::query()
                ->whereIn('id', $earnings->pluck('id'))
                ->update([
                    'status' => CounsellorEarningStatusEnum::processing->value,
                    'counsellor_payout_id' => $payout->id,
                ]);

            return $payout;
        });

        // Dispatched AFTER the transaction commits, not from inside it -- this queue connection's
        // `after_commit` config is false (checked, not assumed), so a job dispatched inside the
        // transaction could be picked up by a worker before the row/claimed-earnings are actually
        // committed, reading a payout that doesn't exist yet from the worker's own connection.
        ProcessCounsellorPayoutJob::dispatch($payout->id);

        return $payout;
    }
}
