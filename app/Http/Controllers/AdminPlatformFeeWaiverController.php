<?php

namespace App\Http\Controllers;

use App\DTOs\PlatformFeeWaiverDTO;
use App\Http\Requests\ManagePlatformFeeWaiverRequest;
use App\Http\Requests\StorePlatformFeeWaiverRequest;
use App\Http\Resources\PlatformFeeWaiverEventResource;
use App\Services\PlatformFeeWaiverService;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Validation\ValidationException;
use Throwable;

// TT-7.10b/SCRUM-468: platform-wide commission-free periods, from Admin → Payouts → Platform settings (the list is
// AdminPayoutController::index's). Only a super administrator gets this far (the FormRequests answer 403 first); the
// service checks again. A refusal on the period itself (overlap, not running, already started) is a field error.
class AdminPlatformFeeWaiverController extends Controller
{
    // TT-7.11e/SCRUM-475: the platform-wide periods' history (created, ended, deleted -- a deleted one's reason too), as
    // JSON. Super administrators only.
    public function events(ManagePlatformFeeWaiverRequest $request)
    {
        try {
            return response()->json([
                'events' => PlatformFeeWaiverEventResource::collection(
                    PlatformFeeWaiverService::new()->getPlatformWideEventsForAdmin($this->dto($request, null))
                )->resolve($request),
            ]);
        } catch (Throwable $th) {
            return $this->failureResponse($request, $th);
        }
    }

    public function store(StorePlatformFeeWaiverRequest $request)
    {
        try {
            PlatformFeeWaiverService::new()->create(
                PlatformFeeWaiverDTO::new()->fromArray(['user' => $request->user()]),
                $request->values(),
            );

            return Redirect::back();
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $th) {
            return $this->failureResponse($request, $th);
        }
    }

    public function end(ManagePlatformFeeWaiverRequest $request, int $waiver)
    {
        try {
            PlatformFeeWaiverService::new()->endNow($this->dto($request, $waiver));

            return Redirect::back();
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $th) {
            return $this->failureResponse($request, $th);
        }
    }

    public function destroy(ManagePlatformFeeWaiverRequest $request, int $waiver)
    {
        try {
            PlatformFeeWaiverService::new()->delete($this->dto($request, $waiver));

            return Redirect::back();
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $th) {
            return $this->failureResponse($request, $th);
        }
    }

    private function dto(ManagePlatformFeeWaiverRequest $request, ?int $waiver): PlatformFeeWaiverDTO
    {
        return PlatformFeeWaiverDTO::new()->fromArray([
            'user' => $request->user(),
            'waiverId' => $waiver,
        ]);
    }
}
