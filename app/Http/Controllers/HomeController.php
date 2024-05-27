<?php

namespace App\Http\Controllers;

use App\Actions\User\GetCounsellorCreationStepOfUserAction;
use App\Http\Resources\PostResource;
use App\Http\Resources\StarredCounsellorResource;
use App\Http\Resources\TherapyMiniResource;
use App\Models\Post;
use App\Services\CounsellorService;
use App\Services\TherapyService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class HomeController extends Controller
{
    public function goHome(Request $request)
    {
        $message = session('message');

        $counsellorService = CounsellorService::new();

        $page = Inertia::render('Home', [
            'counsellorCreationStep' => GetCounsellorCreationStepOfUserAction::new()->execute($request->user()),
            'recentTherapies' => TherapyMiniResource::collection(TherapyService::new()->getRecentTherapies($request->user())),
            'bestCounsellors' => StarredCounsellorResource::collection($counsellorService->getBestCounsellorsForPreviousMonth()),
            'leadingCounsellors' => StarredCounsellorResource::collection($counsellorService->getLeadingCounsellorsForCurrentMonth()),
            'post' => session()->has('postId') ? new PostResource(Post::find(session('postId'))) : null,
            'alert' => session()->has('alert') ? session('alert') : null,
        ]);

        if ($message) {
            $page->with('errorMessage', $message);
        }

        return $page;
    }
}
