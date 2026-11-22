<?php

namespace App\Http\Controllers;

use App\DTOs\CounsellorRecognitionDTO;
use App\Http\Requests\DecideCounsellorRecognitionRequest;
use App\Http\Requests\ViewCounsellorRecognitionRequest;
use App\Http\Resources\CounsellorRecognitionResource;
use App\Services\CounsellorRecognitionService;
use Closure;
use Illuminate\Validation\ValidationException;
use Throwable;

// TT-7.11d1/SCRUM-473: a super administrator recognising counsellors for a month -- the suggestions (JSON, for the
// SCRUM-474 page), and approving, skipping or adding one (JSON for a script, back for an Inertia visit). Only a super
// administrator gets this far (the FormRequests answer 403 first); the service checks again. A refusal on the
// decision itself (the cap, not suggested, not eligible) is a field error.
class AdminCounsellorRecognitionController extends Controller
{
    public function suggestions(ViewCounsellorRecognitionRequest $request)
    {
        try {
            return response()->json(CounsellorRecognitionService::new()->getSuggestions(
                CounsellorRecognitionDTO::new()->fromArray(['user' => $request->user(), 'month' => $request->month()])
            ));
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $th) {
            return $this->failureResponse($request, $th);
        }
    }

    public function approve(DecideCounsellorRecognitionRequest $request)
    {
        return $this->decide($request, fn (CounsellorRecognitionService $service, CounsellorRecognitionDTO $dto) => $service->approve($dto));
    }

    public function skip(DecideCounsellorRecognitionRequest $request)
    {
        return $this->decide($request, fn (CounsellorRecognitionService $service, CounsellorRecognitionDTO $dto) => $service->skip($dto));
    }

    public function add(DecideCounsellorRecognitionRequest $request)
    {
        return $this->decide($request, fn (CounsellorRecognitionService $service, CounsellorRecognitionDTO $dto) => $service->add($dto));
    }

    private function decide(DecideCounsellorRecognitionRequest $request, Closure $decision)
    {
        try {
            $recognition = $decision(CounsellorRecognitionService::new(), CounsellorRecognitionDTO::new()->fromArray([
                'user' => $request->user(),
                'month' => $request->validated('month'),
                'counsellorId' => (int) $request->validated('counsellor_id'),
            ]));

            return $this->successResponse($request, ['recognition' => (new CounsellorRecognitionResource($recognition))->resolve($request)]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $th) {
            return $this->failureResponse($request, $th);
        }
    }
}
