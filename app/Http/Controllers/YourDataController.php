<?php

namespace App\Http\Controllers;

use App\Actions\User\GetYourDataAction;
use App\Support\RetentionPeriods;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

// SCRUM-50 (TT-8.2): "What we hold about you" -- always the signed-in person's own, never anyone else's (no user in the
// route). The retention periods are the privacy policy's own (RetentionPeriods).
class YourDataController extends Controller
{
    public function __invoke(Request $request): Response
    {
        return Inertia::render('Profile/YourData', [
            'data' => GetYourDataAction::new()->execute($request->user()),
            'retentionDays' => RetentionPeriods::days(),
        ]);
    }
}
