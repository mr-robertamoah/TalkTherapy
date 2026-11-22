<?php

namespace App\Http\Controllers;

use App\DTOs\PlatformFeeWaiverDTO;
use App\Http\Requests\ManagePlatformFeeWaiverRequest;
use App\Http\Requests\StoreCounsellorFeeWaiverRequest;
use App\Http\Resources\AdminCounsellorFeeWaiverResource;
use App\Http\Resources\PlatformFeeWaiverEventResource;
use App\Models\Counsellor;
use App\Models\PlatformFeeWaiver;
use App\Services\PlatformFeeWaiverService;
use Closure;
use Illuminate\Validation\ValidationException;
use Throwable;

// TT-7.11e/SCRUM-475: one counsellor's own commission-free periods -- listed (JSON, for the SCRUM-474 recognition
// page), created (optionally for an approved recognition), ended now, or deleted before they start. Every response
// carries its periods as AdminCounsellorFeeWaiverResource, so the page can merge them. Only a super administrator gets
// this far (the FormRequests answer 403 first); the service checks again. A refusal on the period itself (overlap, not
// eligible, not running) is a field error; JSON for a script, back for an Inertia visit.
class AdminCounsellorFeeWaiverController extends Controller
{
    public function index(ManagePlatformFeeWaiverRequest $request, int $counsellor)
    {
        try {
            $result = PlatformFeeWaiverService::new()->getForCounsellorForAdmin($this->dto($request, $counsellor));
            $model = $result['counsellor'];

            return response()->json([
                'counsellor' => [
                    'id' => $model->id,
                    // False once they lose verification, are suspended, deleted or the subject of an open report:
                    // their periods are not ended automatically, so the page alerts on any still applying.
                    'eligible' => $result['eligible'],
                    'verificationState' => $model->verificationState()->value,
                    'suspended' => $model->isSuspended(),
                    'deleted' => $model->trashed(),
                ],
                'waivers' => $result['waivers']
                    ->map(fn (PlatformFeeWaiver $waiver) => (new AdminCounsellorFeeWaiverResource($waiver, $result['eligible']))->resolve($request))
                    ->values(),
                'events' => PlatformFeeWaiverEventResource::collection($result['events'])->resolve($request),
            ]);
        } catch (Throwable $th) {
            return $this->failureResponse($request, $th);
        }
    }

    public function store(StoreCounsellorFeeWaiverRequest $request, int $counsellor)
    {
        return $this->respond($request, fn (PlatformFeeWaiverService $service) => $service->createForCounsellor(
            PlatformFeeWaiverDTO::new()->fromArray([
                'user' => $request->user(),
                'counsellorId' => $counsellor,
                'recognitionId' => $request->recognitionId(),
            ]),
            $request->values(),
        ));
    }

    public function end(ManagePlatformFeeWaiverRequest $request, int $counsellor, int $waiver)
    {
        return $this->respond($request, fn (PlatformFeeWaiverService $service) => $service->endNowForCounsellor($this->dto($request, $counsellor, $waiver)));
    }

    public function destroy(ManagePlatformFeeWaiverRequest $request, int $counsellor, int $waiver)
    {
        return $this->respond($request, fn (PlatformFeeWaiverService $service) => $service->deleteForCounsellor($this->dto($request, $counsellor, $waiver)));
    }

    private function respond(ManagePlatformFeeWaiverRequest $request, Closure $action)
    {
        try {
            $service = PlatformFeeWaiverService::new();
            $waiver = $action($service);
            $waiver->loadMissing(['createdBy', 'endedBy', 'recognition']);
            $counsellor = Counsellor::withTrashed()->find($waiver->counsellor_id);

            return $this->successResponse($request, [
                'waiver' => (new AdminCounsellorFeeWaiverResource($waiver, $counsellor !== null && $service->isEligible($counsellor)))->resolve($request),
            ]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $th) {
            return $this->failureResponse($request, $th);
        }
    }

    private function dto(ManagePlatformFeeWaiverRequest $request, int $counsellor, ?int $waiver = null): PlatformFeeWaiverDTO
    {
        return PlatformFeeWaiverDTO::new()->fromArray([
            'user' => $request->user(),
            'counsellorId' => $counsellor,
            'waiverId' => $waiver,
        ]);
    }
}
