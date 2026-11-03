<?php

namespace App\Actions\Organization;

use App\Actions\Action;
use App\Enums\RequestStatusEnum;
use App\Enums\RequestTypeEnum;
use App\Models\Request;
use App\Notifications\OrganizationCounsellorCompensationChangeExpiredNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

// SCRUM-149 (TT-6.4c): silence is never a valid stalling strategy in either direction -- a
// pending negotiation past its expires_at auto-resolves to the existing `rejected` status
// (not a new enum case; functionally identical to a manual reject everywhere except copy),
// never touching the affiliation's status or existing terms (same fairness-critical
// guarantee as SCRUM-147's manual reject).
//
// Security review (PR #87, post-merge): same lock-then-recheck-then-write and per-row
// isolation as SendCompensationRequestExpiryRemindersAction, for the same reasons.
// SCRUM-376: moved out of AppService.
class ExpireStaleCompensationRequestsAction extends Action
{
    public function execute(): void
    {
        Request::query()
            ->whereType(RequestTypeEnum::organizationCounsellorCompensationChange->value)
            ->wherePending()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->get()
            ->each(function (Request $request) {
                try {
                    DB::transaction(function () use ($request) {
                        $locked = Request::query()->lockForUpdate()->findOrFail($request->id);

                        if ($locked->status !== RequestStatusEnum::pending->value) {
                            return;
                        }

                        $locked->update([
                            'status' => RequestStatusEnum::rejected->value,
                            // SCRUM-150 will need to tell a manual reject apart from an expiry in
                            // its read-only negotiation-state copy, without a new
                            // RequestStatusEnum case.
                            'data' => array_merge($locked->data, ['resolvedBy' => 'expiry']),
                        ]);

                        NotifyCompensationRequestRecipientAction::new()->execute(
                            $locked,
                            new OrganizationCounsellorCompensationChangeExpiredNotification($locked)
                        );
                    });
                } catch (Throwable $exception) {
                    Log::error('Failed to auto-expire a compensation-change request.', [
                        'requestId' => $request->id,
                        'exception' => $exception->getMessage(),
                    ]);
                }
            });
    }
}
