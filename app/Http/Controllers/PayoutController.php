<?php

namespace App\Http\Controllers;

use App\DTOs\PayoutDestinationDTO;
use App\DTOs\TriggerPayoutDTO;
use App\Enums\PayoutDestinationTypeEnum;
use App\Http\Requests\PayoutDestinationRequest;
use App\Services\PayoutService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Throwable;

class PayoutController extends Controller
{
    // TT-7.6d/SCRUM-228 (security requirement carried from TT-7.6a's review): user is built
    // strictly from the authenticated request user, never from request input -- a counsellor can
    // only ever onboard/replace their OWN payout destination through this endpoint.
    public function onboardDestination(PayoutDestinationRequest $request)
    {
        try {
            PayoutService::new()->onboardDestination(PayoutDestinationDTO::new()->fromArray([
                'user' => $request->user(),
                'type' => PayoutDestinationTypeEnum::from($request->validated('type')),
                'accountNumber' => $request->validated('accountNumber'),
                'bankCode' => $request->validated('bankCode'),
                'currency' => $request->validated('currency'),
            ]));

            return Redirect::back();
        } catch (Throwable $th) {
            $status = $this->statusFor($th);
            $message = $this->messageFor($th, $status);

            return Redirect::back()->withErrors(['alert' => $message]);
        }
    }

    // counsellorId is accepted here for TT-7.6e's admin-on-behalf-of trigger -- GetPayoutTargetCounsellorAction
    // only ever honors it when $request->user()->isAdmin() is true, so a plain counsellor
    // supplying someone else's counsellorId falls through to their own payout instead (TT-7.6c's
    // own security-reviewed guarantee, unchanged here).
    public function triggerPayout(Request $request)
    {
        try {
            $payout = PayoutService::new()->triggerPayout(TriggerPayoutDTO::new()->fromArray([
                'user' => $request->user(),
                'counsellorId' => $request->integer('counsellorId') ?: null,
            ]));

            return Redirect::back()->with(['payout' => $payout]);
        } catch (Throwable $th) {
            $status = $this->statusFor($th);
            $message = $this->messageFor($th, $status);

            return Redirect::back()->withErrors(['alert' => $message]);
        }
    }
}
