<?php

namespace App\Actions\Payout;

use App\Actions\Action;
use App\Enums\CounsellorEarningStatusEnum;
use App\Http\Resources\CounsellorEarningResource;
use App\Http\Resources\CounsellorPayoutAccountResource;
use App\Http\Resources\CounsellorPayoutResource;
use App\Models\Counsellor;
use App\Services\SettingsService;

// TT-7.6d/SCRUM-228: read-only aggregation for the counsellor's own payout screen -- deliberately
// not itself an Ensure*/authorization action, since it's only ever called for the counsellor's
// own profile view (gated the same way CounsellorService::getCounsellorData()'s other private
// data already is, at the controller level).
class GetCounsellorPayoutOverviewAction extends Action
{
    public function execute(Counsellor $counsellor): array
    {
        $payoutAccount = $counsellor->payoutAccount;

        // Only earnings in the payout destination's own currency are actually withdrawable
        // (TriggerCounsellorPayoutAction's own rule -- no cross-currency conversion exists).
        // Shown regardless of whether a destination exists yet, so a counsellor can see they have
        // earnings waiting even before onboarding a payout destination.
        $pendingEarnings = $counsellor->earnings()
            ->where('status', CounsellorEarningStatusEnum::pending->value)
            ->when($payoutAccount, fn ($query) => $query->where('currency', $payoutAccount->currency))
            ->latest()
            ->get();

        $availableAmount = (int) $pendingEarnings->sum('net_amount');
        $currency = $payoutAccount?->currency ?? $pendingEarnings->first()?->currency;

        return [
            'payoutAccount' => $payoutAccount ? new CounsellorPayoutAccountResource($payoutAccount) : null,
            'pendingEarnings' => CounsellorEarningResource::collection($pendingEarnings),
            'availableAmount' => $availableAmount,
            'currency' => $currency,
            'minimumPayoutAmount' => $currency ? SettingsService::new()->getMinimumPayoutAmount($currency) : null,
            'payoutHistory' => CounsellorPayoutResource::collection($counsellor->payouts()->latest()->limit(10)->get()),
        ];
    }
}
