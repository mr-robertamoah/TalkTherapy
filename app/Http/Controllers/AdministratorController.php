<?php

namespace App\Http\Controllers;

use App\DTOs\GetCounsellorsForAdminDTO;
use App\DTOs\GetCounsellorStatsForAdminDTO;
use App\DTOs\GetVerificationRequestsDTO;
use App\Http\Resources\AdministratorResource;
use App\Models\Counsellor;
use App\Services\CounsellorService;
use App\Services\RequestService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;

class AdministratorController extends Controller
{
    public function show(Request $request)
    {
        $user = $request->user();
        if (is_null($user) || $user->isNotAdmin()) {
            return Redirect::route('home')->with('message', 'You are not authorized to visit /administrator page.');
        }

        return Inertia::render('Admin', [
            'administrator' => new AdministratorResource($user->administrator)
        ]);
    }

    public function getCounsellors(Request $request)
    {
        ds($request->filterType, $request->filterValue);
        return CounsellorService::new()->geCounsellorsForAdmin(
            GetCounsellorsForAdminDTO::new()->fromArray([
                'user' => $request->user(),
                'filterType' => $request->filterType,
                'filterValue' => $request->filterValue,
            ])
        );
    }

    public function getCounsellorStats(Request $request)
    {
        return CounsellorService::new()->getCounsellorStats(
            GetCounsellorStatsForAdminDTO::new()->fromArray([
                'user' => $request->user(),
                'counsellor' => Counsellor::find($request->counsellorId),
            ])
        );
    }

    public function getVerificationRequests(Request $request)
    {
        return RequestService::new()->getVerificationRequestsForCounsellors(
            GetVerificationRequestsDTO::new()->fromArray([
                'user' => $request->user(),
                'filterType' => $request->filterType,
                'filterValue' => $request->filterValue,
            ])
        );
    }
}
