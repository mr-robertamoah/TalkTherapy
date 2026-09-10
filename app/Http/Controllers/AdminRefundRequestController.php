<?php

namespace App\Http\Controllers;

use App\Http\Resources\RefundRequestResource;
use App\Services\RequestService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;

// TT-7.7c/SCRUM-251: mirrors AdminOrganizationBillingController's own shape exactly -- a
// dedicated page, not another Admin.vue dispatch-table tab (see Payouts.vue's own top comment).
// Approve/reject themselves are NOT handled here -- the page's own action buttons post directly
// to the existing generic `requests.respond` endpoint (already admin-authorized via
// EnsureUserCanRespondToRequestAction's isAdmin() short-circuit), mirroring
// RequestQueueSection.vue's identical precedent. This controller only serves the read-side list.
class AdminRefundRequestController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        if (is_null($user) || $user->isNotAdmin()) {
            return Redirect::route('home')->with('message', 'You are not authorized to visit this page.');
        }

        return Inertia::render('Admin/RefundRequests', [
            'refundRequests' => $this->paginatedResource(RefundRequestResource::collection(
                RequestService::new()->getPendingRefundRequestsForAdmin($user)
            )),
        ]);
    }

    private function paginatedResource(AnonymousResourceCollection $resource): array
    {
        return $resource->response()->getData(true);
    }
}
