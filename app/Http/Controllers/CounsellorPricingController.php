<?php

namespace App\Http\Controllers;

use App\DTOs\CounsellorPricingDTO;
use App\Http\Requests\SetCounsellorPricingRequest;
use App\Http\Resources\CounsellorPricingResource;
use App\Models\Counsellor;
use App\Services\CounsellorPricingService;
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
}
