<?php

namespace App\Http\Controllers;

use App\Http\Resources\AdminBillingSuspendedOrganizationResource;
use App\Models\Organization;
use App\Models\OrganizationInvoice;
use App\Services\OrganizationService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use Throwable;

// TT-7.3b-followup/SCRUM-245: the manual "resolve this" surface SCRUM-238 deliberately left
// unbuilt. Mirrors AdminPayoutController's own shape -- inline admin-gate on index() (a page,
// redirected home on failure), Redirect::back() on the two write actions (mirrors
// PayoutController::triggerPayout()'s identical convention).
class AdminOrganizationBillingController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        if (is_null($user) || $user->isNotAdmin()) {
            return Redirect::route('home')->with('message', 'You are not authorized to visit this page.');
        }

        return Inertia::render('Admin/OrganizationBilling', [
            'organizations' => $this->paginatedResource(AdminBillingSuspendedOrganizationResource::collection(
                OrganizationService::new()->getBillingSuspendedOrganizationsForAdmin($user)
            )),
        ]);
    }

    public function retrySettlement(Request $request)
    {
        try {
            OrganizationService::new()->retryOrganizationInvoiceSettlement(
                $request->user(),
                OrganizationInvoice::find($request->route('organizationInvoiceId'))
            );

            return Redirect::back();
        } catch (Throwable $th) {
            $status = $this->statusFor($th);
            $message = $this->messageFor($th, $status);

            return Redirect::back()->withErrors(['alert' => $message]);
        }
    }

    public function liftSuspension(Request $request)
    {
        try {
            OrganizationService::new()->liftOrganizationBillingSuspension(
                $request->user(),
                Organization::find($request->route('organizationId'))
            );

            return Redirect::back();
        } catch (Throwable $th) {
            $status = $this->statusFor($th);
            $message = $this->messageFor($th, $status);

            return Redirect::back()->withErrors(['alert' => $message]);
        }
    }

    private function paginatedResource(AnonymousResourceCollection $resource): array
    {
        return $resource->response()->getData(true);
    }
}
