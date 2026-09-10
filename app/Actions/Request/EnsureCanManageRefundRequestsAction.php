<?php

namespace App\Actions\Request;

use App\Actions\Action;
use App\Exceptions\TransactionException;
use App\Models\User;

// TT-7.7c/SCRUM-251: platform-admin only -- mirrors EnsureCanManageOrganizationBillingSuspensionAction's
// own reasoning exactly. A refund request's `to` is deliberately null (any admin may respond, per
// TT-7.7a's own design), but the review QUEUE listing has no equivalent built-in gate the way the
// accept/reject endpoint does via EnsureUserCanRespondToRequestAction's isAdmin() check -- this is
// that same gate, applied here too (defense-in-depth, mirrors
// GetBillingSuspendedOrganizationsForAdminAction's own internal check).
class EnsureCanManageRefundRequestsAction extends Action
{
    public function execute(?User $user): void
    {
        if (is_null($user) || $user->isNotAdmin()) {
            throw new TransactionException('You are not authorized to manage refund requests.', 403);
        }
    }
}
