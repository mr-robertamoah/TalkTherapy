<?php

namespace App\Http\Controllers;

use App\DTOs\CounsellorRecognitionDTO;
use App\DTOs\UpdateRecognitionCapsDTO;
use App\Http\Requests\DecideCounsellorRecognitionRequest;
use App\Http\Requests\SearchRecognitionCounsellorsRequest;
use App\Http\Requests\ShowCounsellorRecognitionPageRequest;
use App\Http\Requests\UpdateRecognitionCapsRequest;
use App\Http\Requests\ViewCounsellorRecognitionRequest;
use App\Http\Resources\CounsellorRecognitionResource;
use App\Services\CounsellorRecognitionService;
use App\Services\SettingsService;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Throwable;

// TT-7.11d1/SCRUM-473: a super administrator recognising counsellors for a month -- the suggestions (JSON), and
// approving, skipping or adding one (JSON for a script, back for an Inertia visit). Only a super administrator decides
// (the FormRequests answer 403 first); the service checks again. A refusal on the decision itself (the cap, not
// suggested, not eligible) is a field error.
//
// TT-7.11d2/SCRUM-474: the admin page itself and the suggestions JSON are for any administrator (the page's controls
// for a super administrator only); the "add a counsellor" search and the daily star caps are super-admin only.
class AdminCounsellorRecognitionController extends Controller
{
    public function index(ShowCounsellorRecognitionPageRequest $request)
    {
        $user = $request->user();
        $month = $request->month();
        $dto = CounsellorRecognitionDTO::new()->fromArray(['user' => $user, 'month' => $month]);
        $service = CounsellorRecognitionService::new();
        $current = CarbonImmutable::now('UTC')->startOfMonth();

        return Inertia::render('Admin/Recognition', [
            'month' => $month,
            // The two months a recognition can be decided for, last month first (the default).
            'months' => collect([$current->subMonth(), $current])
                ->map(fn (CarbonImmutable $value) => ['value' => $value->format('Y-m'), 'label' => $value->format('F Y')])
                ->all(),
            'recognition' => $service->getSuggestions($dto),
            'events' => $service->getEvents($dto),
            'caps' => SettingsService::new()->getRecognitionCaps(),
            'capsMax' => UpdateRecognitionCapsRequest::MAX,
            'canManage' => $user->isSuperAdmin(),
            // Nobody decides their own recognition (the service refuses it): the page offers no controls on that row.
            'ownCounsellorId' => $user->counsellor?->id,
        ]);
    }

    public function counsellors(SearchRecognitionCounsellorsRequest $request)
    {
        try {
            return response()->json(['counsellors' => CounsellorRecognitionService::new()->searchCounsellors(
                CounsellorRecognitionDTO::new()->fromArray(['user' => $request->user(), 'search' => $request->search()])
            )]);
        } catch (Throwable $th) {
            return $this->failureResponse($request, $th);
        }
    }

    public function updateCaps(UpdateRecognitionCapsRequest $request)
    {
        try {
            $caps = SettingsService::new()->updateRecognitionCaps(UpdateRecognitionCapsDTO::new()->fromArray([
                'user' => $request->user(),
                'caps' => $request->validated('caps'),
            ]));

            return $this->successResponse($request, ['caps' => $caps]);
        } catch (Throwable $th) {
            return $this->failureResponse($request, $th);
        }
    }

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
