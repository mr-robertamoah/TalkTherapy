<?php

namespace App\Http\Controllers;

use App\Actions\User\GetCounsellorCreationStepOfUserAction;
use App\Http\Resources\PostResource;
use App\Http\Resources\StarredCounsellorResource;
use App\Http\Resources\TherapyMiniResource;
use App\Models\Post;
use App\Services\CounsellorService;
use App\Services\GroupTherapyService;
use App\Services\TherapyService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class HomeController extends Controller
{
    public function goHome(Request $request)
    {
        $message = session('message');
        // SCRUM-221/TT-7.5a: a payment-required redirect (TherapyController::redirectForPaymentRequired)
        // gets its own dedicated, non-alarming banner (built below from this same $message) instead
        // of the generic red "failed" toast every other exception's flashed message triggers.
        $paymentRequired = session()->has('paymentRequired');

        $counsellorService = CounsellorService::new();

        $page = Inertia::render('Home', [
            'counsellorCreationStep' => GetCounsellorCreationStepOfUserAction::new()->execute($request->user()),
            'recentTherapies' => TherapyMiniResource::collection(TherapyService::new()->getRecentTherapies($request->user())),
            'recentGroupTherapies' => TherapyMiniResource::collection(GroupTherapyService::new()->getRecentGroupTherapies($request->user())),
            'bestCounsellors' => StarredCounsellorResource::collection($counsellorService->getBestCounsellorsForPreviousMonth()),
            'leadingCounsellors' => StarredCounsellorResource::collection($counsellorService->getLeadingCounsellorsForCurrentMonth()),
            'post' => session()->has('postId') ? new PostResource(Post::find(session('postId'))) : null,
            'alert' => session()->has('alert') ? session('alert') : null,
            'paymentRequired' => $paymentRequired,
            'paymentRequiredTherapyId' => session('paymentRequiredTherapyId'),
            // TT-7.5b-b2/SCRUM-266: GroupTherapyController::redirectForPaymentRequired() flashes
            // this sibling key (a GroupTherapy id is never the same id-space as a Therapy id, so
            // it can't reuse paymentRequiredTherapyId without the banner linking to the wrong
            // resource). The banner's actual GroupTherapy-aware link/wording is TT-7.5b-b5's job;
            // this only keeps the flashed value from being silently dropped before then.
            'paymentRequiredGroupTherapyId' => session('paymentRequiredGroupTherapyId'),
            'paymentRequiredMessage' => $paymentRequired ? $message : null,
        ]);

        if ($message && ! $paymentRequired) {
            $page->with('errorMessage', $message);
        }

        return $page;
    }
}
