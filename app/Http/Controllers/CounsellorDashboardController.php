<?php

namespace App\Http\Controllers;

use App\Actions\Counsellor\GetCounsellorDashboardAction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;

// SCRUM-391: a counsellor's own dashboard. Someone without a counsellor profile is sent home.
class CounsellorDashboardController extends Controller
{
    public const NOT_A_COUNSELLOR = 'You have to be a counsellor to see a counsellor dashboard.';

    public function __invoke(Request $request)
    {
        $counsellor = $request->user()->counsellor;

        if (! $counsellor) {
            return Redirect::route('home')->withErrors(['alert' => self::NOT_A_COUNSELLOR]);
        }

        return Inertia::render('Counsellor/Dashboard', [
            'dashboard' => GetCounsellorDashboardAction::new()->execute($counsellor, $request->user()),
        ]);
    }
}
