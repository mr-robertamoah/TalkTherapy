<?php

namespace App\Http\Controllers;

use App\DTOs\CounsellorPricingDTO;
use App\Http\Requests\SetCounsellorPricingRequest;
use App\Http\Resources\CounsellorPricingResource;
use App\Models\Counsellor;
use App\Services\CounsellorPricingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Throwable;

class CounsellorPricingController extends Controller
{
    public function store(SetCounsellorPricingRequest $request)
    {
        try {
            $pricings = CounsellorPricingService::new()->setPricing(
                CounsellorPricingDTO::new()->fromArray([
                    'user' => $request->user(),
                    'counsellor' => Counsellor::find($request->route('counsellorId')),
                    'pricings' => $request->pricings,
                ])
            );

            $resource = CounsellorPricingResource::collection($pricings);

            if ($request->acceptsJson()) {
                return response()->json(['pricings' => $resource]);
            }

            return Redirect::back()->with(['pricings' => $resource]);
        } catch (Throwable $th) {
            $status = $this->statusFor($th);
            $message = $this->messageFor($th, $status);

            if ($request->acceptsJson()) {
                return response()->json(['message' => $message], $status);
            }

            return Redirect::back()->withErrors(['alert' => $message]);
        }
    }

    // SCRUM-155 (TT-7.2c): a counsellor can clear their listed pricing entirely -- store() alone
    // can't represent "no pricing" since it always requires at least one entry.
    public function destroy(Request $request)
    {
        try {
            CounsellorPricingService::new()->clearPricing(
                CounsellorPricingDTO::new()->fromArray([
                    'user' => $request->user(),
                    'counsellor' => Counsellor::find($request->route('counsellorId')),
                ])
            );

            if ($request->acceptsJson()) {
                return response()->json(['pricings' => []]);
            }

            return Redirect::back()->with(['pricings' => []]);
        } catch (Throwable $th) {
            $status = $this->statusFor($th);
            $message = $this->messageFor($th, $status);

            if ($request->acceptsJson()) {
                return response()->json(['message' => $message], $status);
            }

            return Redirect::back()->withErrors(['alert' => $message]);
        }
    }
}
