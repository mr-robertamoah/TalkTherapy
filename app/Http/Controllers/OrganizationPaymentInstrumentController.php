<?php

namespace App\Http\Controllers;

use App\DTOs\OrganizationDTO;
use App\DTOs\OrganizationPaymentInstrumentDTO;
use App\Http\Requests\RegisterOrganizationPaymentInstrumentRequest;
use App\Http\Resources\OrganizationPaymentInstrumentResource;
use App\Http\Resources\OrganizationResource;
use App\Models\Organization;
use App\Services\OrganizationService;
use App\Services\SettingsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use Throwable;

// TT-7.3b-i/SCRUM-240: org-admin "add/replace payment method" screen -- mirrors
// OrganizationReconciliationController's own page-controller shape (admin-gated via
// OrganizationService::getOrganization(), Redirect::route('home') on failure since this is a
// browser navigation, not a JSON API call) for index(); initiate() mirrors
// TransactionController::initiate()'s "return {transaction, authorizationUrl} JSON, let the
// frontend do window.location.href" shape instead of PayoutController::onboardDestination()'s
// synchronous redirect-back one, since this flow genuinely needs Paystack's hosted checkout
// redirect (no free "just verify this card" call -- see InitiateOrganizationPaymentInstrumentRegistrationAction).
class OrganizationPaymentInstrumentController extends Controller
{
    public function index(Request $request)
    {
        try {
            $dto = OrganizationDTO::new()->fromArray([
                'user' => $request->user(),
                'organization' => Organization::find($request->route('organizationId')),
            ]);

            $organization = OrganizationService::new()->getOrganization($dto);

            return Inertia::render('Organization/PaymentInstrument', [
                'organization' => new OrganizationResource($organization),
                'paymentInstrument' => $organization->paymentInstrument
                    ? new OrganizationPaymentInstrumentResource($organization->paymentInstrument)
                    : null,
                'verificationAmounts' => SettingsService::new()->getOrganizationPaymentInstrumentVerificationAmounts(),
            ]);
        } catch (Throwable $th) {
            $message = $this->messageFor($th, $this->statusFor($th));

            return Redirect::route('home')->withErrors(['alert' => $message]);
        }
    }

    public function initiate(RegisterOrganizationPaymentInstrumentRequest $request)
    {
        try {
            // organizationId comes from the route, never the body (getFor()'s own precedent in
            // TransactionController) -- there is no separate "which org" input to spoof here.
            $result = OrganizationService::new()->registerPaymentInstrument(
                OrganizationPaymentInstrumentDTO::new()->fromArray([
                    'user' => $request->user(),
                    'organization' => Organization::find($request->route('organizationId')),
                    'currency' => $request->validated('currency'),
                    'callbackUrl' => route('transactions.callback'),
                ])
            );

            return response()->json([
                'transaction' => $result['transaction'],
                'authorizationUrl' => $result['authorizationUrl'],
            ]);
        } catch (Throwable $th) {
            $status = $this->statusFor($th);
            $message = $this->messageFor($th, $status);

            return response()->json(['message' => $message], $status);
        }
    }
}
