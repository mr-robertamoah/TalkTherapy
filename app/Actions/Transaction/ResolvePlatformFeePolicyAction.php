<?php

namespace App\Actions\Transaction;

use App\Actions\Action;
use App\Models\PlatformFeeWaiver;
use App\Services\SettingsService;
use App\Support\PlatformFeePolicy;
use Carbon\CarbonInterface;

// TT-7.10a/SCRUM-467: the platform fee as it stood at $at -- one query for every waiver in force then and one read of
// the percentage, however many counsellors the caller then asks about. $at is the moment the money decision belongs
// to: a payment's transactions.created_at (when checkout started), or now() when a retainer line is recorded.
class ResolvePlatformFeePolicyAction extends Action
{
    public function execute(CarbonInterface $at): PlatformFeePolicy
    {
        $waivers = PlatformFeeWaiver::query()->coveringTime($at)->orderBy('id')->get(['id', 'counsellor_id']);

        return PlatformFeePolicy::from(SettingsService::new()->getPlatformFeePercentage(), $waivers);
    }
}
