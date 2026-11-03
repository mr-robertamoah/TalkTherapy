<?php

namespace App\Actions\Organization;

use App\Actions\Action;
use App\Models\Organization;
use App\Models\Request;
use Illuminate\Notifications\Notification as BaseNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

// SCRUM-148/149: tells whoever a compensation-change request is addressed to (`to`). `to`
// alternates between a Counsellor and the Organization itself, and Organization isn't Notifiable,
// so every one of its admins is notified individually.
// Security review (PR #87, post-merge): `to` can be null if the counterparty was soft-deleted
// while a negotiation was still pending (a real, already-supported lifecycle -- see
// PurgeExpiredSoftDeletedCounsellorsAction) -- logged and skipped rather than left to fatal on a
// null method call, and an org with no (remaining) admins is logged rather than silently no-op'd.
// SCRUM-376: moved out of AppService, and shared with
// CounterOfferOrganizationCounsellorCompensationChangeAction, which had its own copy.
class NotifyCompensationRequestRecipientAction extends Action
{
    public function execute(Request $request, BaseNotification $notification): void
    {
        if (is_null($request->to)) {
            Log::warning('Compensation-change request has no resolvable recipient to notify.', [
                'requestId' => $request->id,
            ]);

            return;
        }

        if ($request->to instanceof Organization) {
            $admins = $request->to->admins;

            if ($admins->isEmpty()) {
                Log::warning('Compensation-change request\'s organization has no admins to notify.', [
                    'requestId' => $request->id,
                    'organizationId' => $request->to->id,
                ]);

                return;
            }

            Notification::send($admins, $notification);

            return;
        }

        $request->to->notify($notification);
    }
}
