<?php

namespace App\Http\Controllers;

use App\Enums\RefundStatusEnum;
use App\Http\Middleware\EnsureUserIsAdmin;
use App\Http\Resources\AdminFailedRefundResource;
use App\Http\Resources\RefundRequestResource;
use App\Models\Refund;
use App\Services\RequestService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;

// TT-7.7c/SCRUM-251: mirrors AdminOrganizationBillingController's own shape exactly -- a
// dedicated page, not another Admin.vue dispatch-table tab (see Payouts.vue's own top comment).
// Approve/reject themselves are NOT handled here -- the page's own action buttons post directly
// to the existing generic `requests.respond` endpoint (admin-authorized by
// EnsureUserCanRespondToRequestAction, refunds being an admin queue type), mirroring
// RequestQueueSection.vue's identical precedent. This controller only serves the read-side list.
class AdminRefundRequestController extends Controller
{
    private const FAILED_REFUNDS_SHOWN = 50;

    public function index(Request $request)
    {
        $user = $request->user();
        if (is_null($user) || $user->isNotAdmin()) {
            return Redirect::route('home')->with('message', EnsureUserIsAdmin::MESSAGE);
        }

        // SCRUM-387 (review): the failed refunds too -- the admin overview counts them and links
        // here. Nothing retries one, so they are listed only, newest first.
        $failed = Refund::query()->where('status', RefundStatusEnum::failed->value);

        return Inertia::render('Admin/RefundRequests', [
            'refundRequests' => $this->paginatedResource(RefundRequestResource::collection(
                RequestService::new()->getPendingRefundRequestsForAdmin($user)
            )),
            'failedRefunds' => AdminFailedRefundResource::collection(
                (clone $failed)
                    ->with([
                        'requestedBy',
                        'statusHistories' => fn ($query) => $query->where('status', RefundStatusEnum::failed->value)->latest('id'),
                    ])
                    ->latest('id')
                    ->limit(self::FAILED_REFUNDS_SHOWN)
                    ->get()
            )->resolve(),
            'failedRefundsTotal' => $failed->count(),
        ]);
    }

    private function paginatedResource(AnonymousResourceCollection $resource): array
    {
        return $resource->response()->getData(true);
    }
}
